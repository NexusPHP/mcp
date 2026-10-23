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

namespace Nexus\Mcp\Tests\Server\Transport;

use Nexus\Mcp\Server\Transport\RelayedStdinStream;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use function Amp\ByteStream\buffer;
use function Amp\delay;

/**
 * Starts real subprocesses, declaring no coverage of `StdioServerTransport` so Infection never selects these tests and scores a false kill.
 *
 * @internal
 */
#[CoversClass(RelayedStdinStream::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class RelayedStdinStreamTest extends AbstractMcpTestCase
{
    public function testTheRelayCopiesEveryLineAndEndsWithItsInput(): void
    {
        $input = tmpfile();
        self::assertIsResource($input);
        fwrite($input, "first\nsecond\n");
        rewind($input);

        $stream = new RelayedStdinStream($input);

        self::assertSame("first\nsecond\n", buffer($stream));
        self::assertNull($stream->read());
        self::assertFalse($stream->isReadable());
    }

    public function testClosingTheStreamTerminatesTheRelay(): void
    {
        [$writer, $reader] = self::createSocketPair();
        $stream = new RelayedStdinStream($reader);
        $relay = self::getRelay($stream);
        $closed = false;
        $stream->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isClosed());

        $stream->close();
        delay(0);

        self::assertTrue($stream->isClosed());
        self::assertTrue($closed);
        self::assertFalse(\is_resource($relay), 'The relay is reaped on close.');
        fclose($writer);
    }

    public function testClosingAClosedStreamIsANoOp(): void
    {
        [$writer, $reader] = self::createSocketPair();
        $stream = new RelayedStdinStream($reader);
        $stream->close();

        $stream->close();

        self::assertTrue($stream->isClosed());
        fclose($writer);
    }

    public function testReleasingTheStreamTerminatesTheRelay(): void
    {
        [$writer, $reader] = self::createSocketPair();
        $stream = new RelayedStdinStream($reader);
        $relay = self::getRelay($stream);

        unset($stream);

        self::assertFalse(\is_resource($relay), 'The relay is reaped on release.');
        fclose($writer);
    }

    /**
     * @return array{resource, resource}
     */
    private static function createSocketPair(): array
    {
        $pair = stream_socket_pair('Windows' === \PHP_OS_FAMILY ? \STREAM_PF_INET : \STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        self::assertIsArray($pair);
        $writer = array_shift($pair);
        $reader = array_shift($pair);
        self::assertIsResource($writer);
        self::assertIsResource($reader);

        return [$writer, $reader];
    }

    /**
     * @return resource
     */
    private static function getRelay(RelayedStdinStream $stream): mixed
    {
        $relay = (new \ReflectionProperty($stream, 'relay'))->getValue($stream);
        self::assertIsResource($relay);

        return $relay;
    }
}
