<?php

declare(strict_types=1);

/**
 * This file is part of the Nexus MCP SDK package.
 *
 * (c) 2026 John Paul E. Balandan, CPA <paulbalandan@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Nexus\Mcp\Tests\Server\Transport\Http;

use Nexus\Mcp\Server\Transport\Http\SseResponseStream;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Revolt\EventLoop;

use function Amp\async;

/**
 * @internal
 */
#[CoversClass(SseResponseStream::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class SseResponseStreamTest extends AbstractMcpTestCase
{
    public function testReadsAllPushedContentThenReportsEof(): void
    {
        $stream = new SseResponseStream(60.0, static fn(): null => null);
        $stream->push('alpha');
        $stream->push('beta');
        $stream->end();

        self::assertSame('alphabeta', $stream->getContents());
        self::assertTrue($stream->eof());
        self::assertSame(9, $stream->tell());
    }

    public function testReadHonoursTheRequestedLength(): void
    {
        $stream = new SseResponseStream(60.0, static fn(): null => null);
        $stream->push('abcdef');
        $stream->end();

        self::assertSame('abc', $stream->read(3));
        self::assertSame('def', $stream->read(3));
        self::assertSame('', $stream->read(3));
    }

    public function testGetContentsReadsPayloadsLargerThanOneChunk(): void
    {
        $stream = new SseResponseStream(60.0, static fn(): null => null);
        $payload = str_repeat('x', 10_000);
        $stream->push($payload);
        $stream->end();

        self::assertSame($payload, $stream->getContents());
    }

    public function testPushWakesABlockedReader(): void
    {
        $chunk = async(static function (): string {
            $stream = new SseResponseStream(60.0, static fn(): null => null);

            EventLoop::queue(static function () use ($stream): void {
                $stream->push('woke');
            });

            return $stream->read(8_192);
        })->await();

        self::assertSame('woke', $chunk);
    }

    public function testEndWakesABlockedReader(): void
    {
        $chunk = async(static function (): string {
            $stream = new SseResponseStream(60.0, static fn(): null => null);

            EventLoop::queue(static function () use ($stream): void {
                $stream->end();
            });

            return $stream->read(8_192);
        })->await();

        self::assertSame('', $chunk);
    }

    public function testAnIdleReadYieldsAKeepAliveFrame(): void
    {
        $frame = async(static function (): string {
            $stream = new SseResponseStream(0.01, static fn(): null => null);

            // The keep-alive timeout is unreferenced, so keep the loop alive long enough for it to fire.
            $anchor = EventLoop::delay(1.0, static fn(): null => null);

            try {
                return $stream->read(8_192);
            } finally {
                EventLoop::cancel($anchor);
            }
        })->await();

        self::assertSame(": keep-alive\n\n", $frame);
    }

    public function testAKeepAliveFrameHonoursTheRequestedLengthAndAdvancesTheOffset(): void
    {
        /** @var array{string, string, int} $observed */
        $observed = async(static function (): array {
            $stream = new SseResponseStream(0.01, static fn(): null => null);
            $anchor = EventLoop::delay(1.0, static fn(): null => null);

            try {
                // A host copying into a fixed buffer must not be handed more bytes than it asked for, and
                // every byte emitted has to count towards tell().
                $first = $stream->read(4);
                $rest = $stream->read(8_192);

                return [$first, $rest, $stream->tell()];
            } finally {
                EventLoop::cancel($anchor);
            }
        })->await();

        self::assertSame(': ke', $observed[0]);
        self::assertSame("ep-alive\n\n", $observed[1]);
        self::assertSame(14, $observed[2]);
    }

    public function testEofTogglesAsTheStreamFillsDrainsAndEnds(): void
    {
        $stream = new SseResponseStream(60.0, static fn(): null => null);
        $stream->push('ab');
        self::assertFalse($stream->eof());

        self::assertSame('ab', $stream->read(2));
        // Drained but not ended: the consumer must keep waiting for more frames.
        self::assertFalse($stream->eof());

        $stream->end();
        self::assertTrue($stream->eof());
    }

    public function testFramesPushedAfterEndAreDiscarded(): void
    {
        $stream = new SseResponseStream(60.0, static fn(): null => null);
        $stream->end();
        $stream->push('late');

        self::assertSame('', $stream->read(10));
        self::assertTrue($stream->eof());
    }

    public function testToStringDrainsTheStream(): void
    {
        $stream = new SseResponseStream(60.0, static fn(): null => null);
        $stream->push('hello');
        $stream->end();

        self::assertSame('hello', (string) $stream);
    }

    public function testExposesReadOnlyNonSeekableCapabilities(): void
    {
        $stream = new SseResponseStream(60.0, static fn(): null => null);

        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
        self::assertNull($stream->getSize());
    }

    public function testGetMetadataReturnsAnEmptyMapOrNullForAKey(): void
    {
        $stream = new SseResponseStream(60.0, static fn(): null => null);

        self::assertSame([], $stream->getMetadata());
        self::assertNull($stream->getMetadata('uri'));
    }

    public function testWriteThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^An SSE response body is not writable\.$/');

        (new SseResponseStream(60.0, static fn(): null => null))->write('nope');
    }

    public function testSeekThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^An SSE response body is not seekable\.$/');

        (new SseResponseStream(60.0, static fn(): null => null))->seek(0);
    }

    public function testRewindThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^An SSE response body is not seekable\.$/');

        (new SseResponseStream(60.0, static fn(): null => null))->rewind();
    }

    public function testCloseEndsTheStreamAndInvokesTheCloseHook(): void
    {
        $closed = false;
        $stream = new SseResponseStream(60.0, static function () use (&$closed): void {
            $closed = true;
        });

        $stream->close();

        self::assertTrue($closed);
        self::assertTrue($stream->eof());
    }

    public function testDetachEndsTheStreamAndReturnsNull(): void
    {
        $closed = false;
        $stream = new SseResponseStream(60.0, static function () use (&$closed): void {
            $closed = true;
        });

        self::assertNull($stream->detach());
        self::assertTrue($closed);
    }
}
