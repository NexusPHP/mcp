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
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Server\Transport\StdioServerTransport;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
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
            $this->expectExceptionMessageIs(\sprintf('%s has already been started.', StdioServerTransport::class));

            $transport->start();
        } finally {
            EventLoop::run();
        }
    }

    public function testSessionIdIsAlwaysNull(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        self::assertNull($transport->getSessionId());
    }

    public function testEmitsDecodedEnvelope(): void
    {
        $transport = self::buildTransportReading(['{"jsonrpc":"2.0","id":1,"method":"server/discover"}'."\n"]);
        $envelopes = [];
        $transport->onMessage(static function (array $envelope) use (&$envelopes): void {
            $envelopes[] = $envelope;
        });

        $transport->start();
        EventLoop::run();

        self::assertSame(
            [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover']],
            $envelopes,
        );
    }

    public function testEmitsDrainBeforeCloseOnEof(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());
        $events = [];
        $transport->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $transport->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $transport->start();
        EventLoop::run();

        self::assertSame(['drain', 'close'], $events);
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
                "{not json}\n".'{"jsonrpc":"2.0","id":1,"method":"server/discover"}'."\n",
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
            [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover']],
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
        $transport->send(self::discoverRequest(99));

        EventLoop::run();
        $writable->close();

        self::assertSame(self::expectedLine(99), $writable->buffer());
    }

    public function testSendIgnoresContextForStdio(): void
    {
        $writable = new WritableBuffer();
        $transport = new StdioServerTransport(new ReadableBuffer(''), $writable);

        $transport->start();
        $transport->send(self::discoverRequest(7), new SendContext(new RequestId(id: 99)));

        EventLoop::run();
        $writable->close();

        self::assertSame(self::expectedLine(7), $writable->buffer());
    }

    public function testSendFailureClosesAndRethrows(): void
    {
        $boom = new \RuntimeException('stdout exploded');
        $readable = new ReadableIterableStream(new \ArrayIterator([]));
        $writable = new ThrowingWritableStream($boom);
        $transport = new StdioServerTransport($readable, $writable);

        $transport->start();

        try {
            $transport->send(self::discoverRequest(1));
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
        $this->expectExceptionMessageIs('Cannot send before start() has been called.');

        $transport->send(self::discoverRequest(1));
    }

    public function testSendAfterCloseThrows(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        $transport->start();
        $transport->close();

        EventLoop::run();

        try {
            $transport->send(self::discoverRequest(1));
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
        $this->expectExceptionMessageIs('Cannot start on a closed transport.');

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
    public function testLoggerLabelIsWiredAsStdioServer(string $level, string $template): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer(), $logger);

        $transport->start();
        EventLoop::run();

        $matches = $logger->recordsMatching($level, $template);
        self::assertNotEmpty($matches);
        self::assertSame('Stdio server', $matches[0]['context']['label'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLoggerLabelIsWiredAsStdioServerCases(): iterable
    {
        yield 'start' => [LogLevel::INFO, '{label} transport started.'];

        yield 'close' => [LogLevel::INFO, '{label} transport closed.'];
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

    private static function discoverRequest(int $id): DiscoverRequest
    {
        return new DiscoverRequest(
            id: new RequestId(id: $id),
            params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()),
        );
    }

    private static function expectedLine(int $id): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";
    }
}
