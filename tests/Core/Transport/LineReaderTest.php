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

namespace Nexus\Mcp\Tests\Core\Transport;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Nexus\Mcp\Core\Transport\LineReader;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(LineReader::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class LineReaderTest extends AbstractMcpTestCase
{
    public function testConstructorRejectsNonPositiveMaxLineBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^maxLineBytes must be a positive integer, 0 given\.$/');

        new LineReader(new ReadableBuffer(''), maxLineBytes: 0);
    }

    public function testYieldsLinesFromSingleChunk(): void
    {
        $reader = new LineReader(new ReadableBuffer("alpha\nbeta\ngamma\n"));

        self::assertSame(['alpha', 'beta', 'gamma'], iterator_to_array($reader->getLines(), false));
    }

    public function testYieldsLinesSplitAcrossChunks(): void
    {
        $reader = new LineReader(new ReadableIterableStream(new \ArrayIterator([
            'al',
            'pha',
            "\nbeta\n",
            'gamm',
            "a\n",
        ])));

        self::assertSame(['alpha', 'beta', 'gamma'], iterator_to_array($reader->getLines(), false));
    }

    public function testStripsTrailingCarriageReturnSoCrlfYieldsSameAsLf(): void
    {
        $reader = new LineReader(new ReadableBuffer("alpha\r\nbeta\r\n"));

        self::assertSame(['alpha', 'beta'], iterator_to_array($reader->getLines(), false));
    }

    public function testSkipsBlankLines(): void
    {
        $reader = new LineReader(new ReadableBuffer("\n\nalpha\n\n\nbeta\n\n"));

        self::assertSame(['alpha', 'beta'], iterator_to_array($reader->getLines(), false));
    }

    public function testYieldsTrailingPartialLineAtEofWhenNonEmpty(): void
    {
        $reader = new LineReader(new ReadableBuffer("alpha\nbeta"));

        self::assertSame(['alpha', 'beta'], iterator_to_array($reader->getLines(), false));
    }

    public function testStripsTrailingCarriageReturnOnPartialLineAtEof(): void
    {
        $reader = new LineReader(new ReadableBuffer("alpha\nbeta\r"));

        self::assertSame(['alpha', 'beta'], iterator_to_array($reader->getLines(), false));
    }

    public function testEmptyStreamYieldsNothing(): void
    {
        $reader = new LineReader(new ReadableBuffer(''));

        self::assertSame([], iterator_to_array($reader->getLines(), false));
    }

    public function testAcceptsLineAtExactlyTheConfiguredCap(): void
    {
        $line = str_repeat('a', 64);
        $reader = new LineReader(new ReadableBuffer($line."\n"), maxLineBytes: 64);

        self::assertSame([$line], iterator_to_array($reader->getLines(), false));
    }

    public function testThrowsWhenPendingBufferExceedsCapBeforeDelimiter(): void
    {
        $reader = new LineReader(
            new ReadableIterableStream(new \ArrayIterator([str_repeat('a', 65)])),
            maxLineBytes: 64,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Line exceeded the 64-byte cap before a delimiter was found\.$/');

        iterator_to_array($reader->getLines(), false);
    }

    public function testThrowsWhenCompletedLineExceedsCap(): void
    {
        $reader = new LineReader(
            new ReadableBuffer(str_repeat('a', 65)."\n"),
            maxLineBytes: 64,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Line exceeded the 64-byte cap before a delimiter was found\.$/');

        iterator_to_array($reader->getLines(), false);
    }

    public function testThrowsWhenTrailingPartialLineAtEofExceedsCap(): void
    {
        // The cap trips during the read loop, not at EOF. A 65-byte chunk with
        // no delimiter is rejected on the post-chunk guard.
        $reader = new LineReader(
            new ReadableIterableStream(new \ArrayIterator(['a', str_repeat('b', 65)])),
            maxLineBytes: 64,
        );

        $this->expectException(\RuntimeException::class);

        iterator_to_array($reader->getLines(), false);
    }
}
