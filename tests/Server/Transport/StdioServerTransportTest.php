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

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\WritableBuffer;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Server\Transport\StdioServerTransport;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\ThrowingWritableStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

/**
 * @internal
 */
#[CoversClass(StdioServerTransport::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class StdioServerTransportTest extends TestCase
{
    public function testStartAfterStartThrows(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        $transport->start();

        try {
            $this->expectException(TransportAlreadyStartedException::class);
            $this->expectExceptionMessage(\sprintf('%s has already been started.', StdioServerTransport::class));

            $transport->start();
        } finally {
            EventLoop::run();
        }
    }

    public function testSessionIdIsAlwaysNull(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        self::assertNull($transport->sessionId());
    }

    public function testEmitsDecodedEnvelope(): void
    {
        $transport = self::buildTransportReading(['{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n"]);
        $envelopes = [];
        $transport->onMessage(static function (array $envelope) use (&$envelopes): void {
            $envelopes[] = $envelope;
        });

        $transport->start();
        EventLoop::run();

        self::assertSame(
            [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']],
            $envelopes,
        );
    }

    public function testOversizedInboundLineSurfacesAsTransportErrorAndClose(): void
    {
        $transport = new StdioServerTransport(
            new ReadableIterableStream(new \ArrayIterator([str_repeat('a', 65)])),
            new WritableBuffer(),
            maxLineBytes: 64,
        );
        $errors = [];
        $closes = 0;
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });
        $transport->onClose(static function () use (&$closes): void {
            ++$closes;
        });

        $transport->start();
        EventLoop::run();

        self::assertCount(1, $errors);
        self::assertInstanceOf(\RuntimeException::class, $errors[0]);
        self::assertSame(1, $closes);
    }

    public function testMalformedJsonRespondsWithParseErrorAndContinues(): void
    {
        $writable = new WritableBuffer();
        $transport = new StdioServerTransport(
            new ReadableIterableStream(new \ArrayIterator([
                "{not json}\n".'{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n",
            ])),
            $writable,
        );
        $envelopes = [];
        $transport->onMessage(static function (array $envelope) use (&$envelopes): void {
            $envelopes[] = $envelope;
        });

        $transport->start();
        EventLoop::run();
        $writable->close();

        self::assertSame(
            [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']],
            $envelopes,
            'The valid envelope after a malformed line must still reach listeners.',
        );
        self::assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32700,"message":"Parse error"}}'."\n",
            $writable->buffer(),
        );
    }

    public function testNonObjectJsonRespondsWithInvalidRequestEnvelope(): void
    {
        $writable = new WritableBuffer();
        $transport = new StdioServerTransport(
            new ReadableIterableStream(new \ArrayIterator(["[1,2,3]\n"])),
            $writable,
        );

        $transport->start();
        EventLoop::run();
        $writable->close();

        self::assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid request"}}'."\n",
            $writable->buffer(),
        );
    }

    public function testSendWritesSerializedLine(): void
    {
        $writable = new WritableBuffer();
        $transport = new StdioServerTransport(new ReadableBuffer(''), $writable);

        $transport->start();
        $transport->send(new PingRequest(new RequestId(99)));

        EventLoop::run();
        $writable->close();

        self::assertSame(
            '{"jsonrpc":"2.0","id":99,"method":"ping"}'."\n",
            $writable->buffer(),
        );
    }

    public function testSendIgnoresContextForStdio(): void
    {
        $writable = new WritableBuffer();
        $transport = new StdioServerTransport(new ReadableBuffer(''), $writable);

        $transport->start();
        $transport->send(new PingRequest(new RequestId(7)), new SendContext(new RequestId(99)));

        EventLoop::run();
        $writable->close();

        self::assertSame(
            '{"jsonrpc":"2.0","id":7,"method":"ping"}'."\n",
            $writable->buffer(),
        );
    }

    public function testSendFailureClosesAndRethrows(): void
    {
        $boom = new \RuntimeException('stdout exploded');
        $readable = new ReadableIterableStream(new \ArrayIterator([]));
        $writable = new ThrowingWritableStream($boom);
        $transport = new StdioServerTransport($readable, $writable);

        $transport->start();

        try {
            $transport->send(new PingRequest(new RequestId(1)));
            self::fail('Expected send() to rethrow the underlying write failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($boom, $caught);
        }

        self::assertTrue($readable->isClosed(), 'send() failure must close the readable stream synchronously.');
        self::assertTrue($writable->isClosed(), 'send() failure must close the writable stream synchronously.');

        EventLoop::run();
    }

    public function testSendBeforeStartThrows(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        $this->expectException(TransportNotStartedException::class);
        $this->expectExceptionMessage('Cannot send before start() has been called.');

        $transport->send(new PingRequest(new RequestId(1)));
    }

    public function testSendAfterCloseThrows(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        $transport->start();
        $transport->close();

        EventLoop::run();

        try {
            $transport->send(new PingRequest(new RequestId(1)));
            self::fail('Expected send() to throw on a closed transport.');
        } catch (TransportAlreadyClosedException $caught) {
            self::assertSame('Cannot send on a closed transport.', $caught->getMessage());
            self::assertNull(
                $caught->getPrevious(),
                'Pre-flight rejection has no underlying byte-stream cause. Only the concurrent-close wrap path carries a previous.',
            );
        }
    }

    public function testStartAfterCloseThrows(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);
        $this->expectExceptionMessage('Cannot start on a closed transport.');

        $transport->start();
    }

    public function testCloseClosesUnderlyingStreams(): void
    {
        $readable = new ReadableIterableStream(new \ArrayIterator([]));
        $writable = new WritableBuffer();
        $transport = new StdioServerTransport($readable, $writable);

        self::assertFalse($readable->isClosed());
        self::assertFalse($writable->isClosed());

        $transport->close();

        self::assertTrue($readable->isClosed());
        self::assertTrue($writable->isClosed());
    }

    #[DataProvider('provideLoggerLabelIsWiredAsStdioServerCases')]
    public function testLoggerLabelIsWiredAsStdioServer(string $level, string $message): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer(), $logger);

        $transport->start();
        EventLoop::run();

        self::assertContains($message, $logger->messagesAtLevel($level));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLoggerLabelIsWiredAsStdioServerCases(): iterable
    {
        yield 'start' => [LogLevel::INFO, 'Stdio server transport started.'];

        yield 'close' => [LogLevel::INFO, 'Stdio server transport closed.'];
    }

    /**
     * @param list<string> $chunks
     */
    private static function buildTransportReading(array $chunks): StdioServerTransport
    {
        return new StdioServerTransport(
            new ReadableIterableStream(new \ArrayIterator($chunks)),
            new WritableBuffer(),
        );
    }
}
