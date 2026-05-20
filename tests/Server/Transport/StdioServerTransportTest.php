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
use Nexus\Mcp\Core\Schema\Error\InvalidParamsError;
use Nexus\Mcp\Core\Schema\Error\ParseError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\CancelledNotificationParams;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Server\Transport\StdioServerTransport;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Server\ThrowingReadableStream;
use Nexus\Mcp\Tests\Fixtures\Server\ThrowingWritableStream;
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
    private ?StdioServerTransport $transportUnderConcurrentClose = null;

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
        self::captureEnvelopesInto($transport, $envelopes);

        $transport->start();
        EventLoop::run();

        self::assertSame(
            [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']],
            $envelopes,
        );
    }

    public function testEmitsMultipleEnvelopesInOneChunk(): void
    {
        $line1 = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
        $line2 = '{"jsonrpc":"2.0","method":"notifications/initialized"}';
        $transport = self::buildTransportReading([$line1."\n".$line2."\n"]);
        $envelopes = [];
        self::captureEnvelopesInto($transport, $envelopes);

        $transport->start();
        EventLoop::run();

        self::assertSame(
            [
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ],
            $envelopes,
        );
    }

    public function testEmitsEnvelopeSplitAcrossChunks(): void
    {
        $transport = self::buildTransportReading([
            '{"jsonrpc":"2.0","id":',
            '42,"method":',
            '"ping"}'."\n",
        ]);
        $envelopes = [];
        self::captureEnvelopesInto($transport, $envelopes);

        $transport->start();
        EventLoop::run();

        self::assertSame(
            [['jsonrpc' => '2.0', 'id' => 42, 'method' => 'ping']],
            $envelopes,
        );
    }

    public function testHandlesCrlfLineEndings(): void
    {
        $transport = self::buildTransportReading(['{"jsonrpc":"2.0","id":7,"method":"ping"}'."\r\n"]);
        $envelopes = [];
        self::captureEnvelopesInto($transport, $envelopes);

        $transport->start();
        EventLoop::run();

        self::assertCount(1, $envelopes);
    }

    public function testSkipsBlankLines(): void
    {
        $transport = self::buildTransportReading([
            "\n\n".'{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n\n",
        ]);
        $envelopes = [];
        $errors = [];
        self::captureEnvelopesInto($transport, $envelopes);
        self::captureErrorsInto($transport, $errors);

        $transport->start();
        EventLoop::run();

        self::assertCount(1, $envelopes);
        self::assertSame([], $errors);
    }

    public function testSkipsCrlfBlankLines(): void
    {
        $transport = self::buildTransportReading([
            "\r\n".'{"jsonrpc":"2.0","id":1,"method":"ping"}'."\r\n",
        ]);
        $envelopes = [];
        $errors = [];
        self::captureEnvelopesInto($transport, $envelopes);
        self::captureErrorsInto($transport, $errors);

        $transport->start();
        EventLoop::run();

        self::assertCount(1, $envelopes);
        self::assertSame([], $errors);
    }

    public function testOversizedInboundLineSurfacesAsTransportErrorAndClose(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(
            new ReadableIterableStream(new \ArrayIterator([str_repeat('a', 65)])),
            new WritableBuffer(),
            $logger,
            maxLineBytes: 64,
        );
        $errors = [];
        $closes = 0;
        self::captureErrorsInto($transport, $errors);
        self::countClosesInto($transport, $closes);

        $transport->start();
        EventLoop::run();

        self::assertCount(1, $errors);
        self::assertInstanceOf(\RuntimeException::class, $errors[0]);
        self::assertSame(1, $closes);

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Stdio transport read loop failed. Closing.');
        self::assertCount(1, $matches);
        self::assertSame($errors[0], $matches[0]['context']['exception'] ?? null);
    }

    public function testEofFiresCloseListenerExactlyOnce(): void
    {
        $transport = self::buildTransportReading([]);
        $closes = 0;
        self::countClosesInto($transport, $closes);

        $transport->start();
        EventLoop::run();

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
        $errors = [];
        self::captureEnvelopesInto($transport, $envelopes);
        self::captureErrorsInto($transport, $errors);

        $transport->start();
        EventLoop::run();
        $writable->close();

        self::assertSame([], $errors);
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

    /**
     * @param non-empty-string $payload
     */
    #[DataProvider('provideNonObjectJsonRespondsWithInvalidRequestAndFiresOnErrorCases')]
    public function testNonObjectJsonRespondsWithInvalidRequestAndFiresOnError(string $payload): void
    {
        $writable = new WritableBuffer();
        $transport = new StdioServerTransport(
            new ReadableIterableStream(new \ArrayIterator([$payload."\n"])),
            $writable,
        );
        $envelopes = [];
        $errors = [];
        self::captureEnvelopesInto($transport, $envelopes);
        self::captureErrorsInto($transport, $errors);

        $transport->start();
        EventLoop::run();
        $writable->close();

        self::assertSame([], $envelopes);
        self::assertCount(1, $errors);
        self::assertInstanceOf(\InvalidArgumentException::class, $errors[0]);
        self::assertSame(
            '{"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid request"}}'."\n",
            $writable->buffer(),
        );
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideNonObjectJsonRespondsWithInvalidRequestAndFiresOnErrorCases(): iterable
    {
        yield 'array' => ['[1,2,3]'];

        yield 'batch (removed in 2025-11-25)' => ['[{"jsonrpc":"2.0","id":1,"method":"ping"}]'];

        yield 'scalar' => ['42'];

        yield 'string' => ['"hi"'];

        yield 'null' => ['null'];
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

    public function testSendEmitsUnescapedSlashesAndUnicode(): void
    {
        $writable = new WritableBuffer();
        $transport = new StdioServerTransport(new ReadableBuffer(''), $writable);

        $transport->start();
        $transport->send(new CancelledNotification(new CancelledNotificationParams(
            new RequestId(1),
            'café/done',
        )));

        EventLoop::run();
        $writable->close();

        $output = $writable->buffer();
        self::assertStringContainsString('notifications/cancelled', $output);
        self::assertStringContainsString('café/done', $output);
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

        $closes = 0;
        self::countClosesInto($transport, $closes);

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

        self::assertSame(1, $closes);
    }

    public function testSendDuringConcurrentCloseWrapsByteStreamFailureInTransportAlreadyClosedException(): void
    {
        $boom = new \RuntimeException('stdout was concurrently closed');
        $readable = new ReadableIterableStream(new \ArrayIterator([]));
        $writable = new ThrowingWritableStream(
            $boom,
            beforeThrow: function (): void {
                // Simulate a different fiber closing the transport while write() is suspended.
                $sut = $this->transportUnderConcurrentClose;

                if (! $sut instanceof StdioServerTransport) {
                    throw new \LogicException('Transport reference must be wired before this callback fires.');
                }

                $sut->close();
            },
        );
        $this->transportUnderConcurrentClose = new StdioServerTransport($readable, $writable);

        $this->transportUnderConcurrentClose->start();

        try {
            $this->transportUnderConcurrentClose->send(new PingRequest(new RequestId(1)));
            self::fail('Expected send() to throw on the concurrent close.');
        } catch (TransportAlreadyClosedException $caught) {
            self::assertSame(
                'Cannot send on a closed transport.',
                $caught->getMessage(),
                'Concurrent-close failures must surface as TransportAlreadyClosedException so the dispatcher demotes uniformly.',
            );
            self::assertSame(
                $boom,
                $caught->getPrevious(),
                'The original byte-stream failure must be preserved as the previous exception for audit purposes.',
            );
        }

        EventLoop::run();
    }

    public function testMessageListenerThrowFiresErrorListener(): void
    {
        $boom = new \RuntimeException('listener exploded');
        $transport = self::buildTransportReading(['{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n"]);

        $errors = [];
        $transport->onMessage(static function (array $envelope) use ($boom): void {
            throw $boom;
        });
        self::captureErrorsInto($transport, $errors);

        $transport->start();
        EventLoop::run();

        self::assertSame([$boom], $errors);
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

    public function testCloseBeforeStartFiresCloseListener(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        $closes = 0;
        self::countClosesInto($transport, $closes);

        $transport->close();

        self::assertSame(1, $closes);
    }

    public function testCloseBeforeStartIsIdempotent(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        $closes = 0;
        self::countClosesInto($transport, $closes);

        $transport->close();
        $transport->close();

        self::assertSame(1, $closes);
    }

    public function testStartAfterCloseThrows(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);
        $this->expectExceptionMessage('Cannot start on a closed transport.');

        $transport->start();
    }

    public function testCloseIsIdempotent(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        $closes = 0;
        self::countClosesInto($transport, $closes);

        $transport->start();
        $transport->close();
        $transport->close();

        EventLoop::run();

        self::assertSame(1, $closes);
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

    public function testCloseFiresEvenWhenErrorListenerThrows(): void
    {
        $previous = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static function (): void {});

        try {
            $transport = new StdioServerTransport(
                new ThrowingReadableStream(new \RuntimeException('stdin exploded')),
                new WritableBuffer(),
            );

            $closes = 0;
            $transport->onError(static function (): void {
                throw new \RuntimeException('error listener exploded');
            });
            $transport->onClose(static function () use (&$closes): void {
                ++$closes;
            });

            $transport->start();
            EventLoop::run();

            self::assertSame(1, $closes);
        } finally {
            EventLoop::setErrorHandler($previous);
        }
    }

    public function testStreamReadFailureFiresOnError(): void
    {
        $boom = new \RuntimeException('stdin exploded');
        $transport = new StdioServerTransport(new ThrowingReadableStream($boom), new WritableBuffer());

        $errors = [];
        self::captureErrorsInto($transport, $errors);
        $closes = 0;
        self::countClosesInto($transport, $closes);

        $transport->start();
        EventLoop::run();

        self::assertSame([$boom], $errors);
        self::assertSame(1, $closes);
    }

    public function testLoggerEmitsInfoOnStart(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer(), $logger);

        $transport->start();
        EventLoop::run();

        self::assertContains(
            'Stdio transport started. Reading from stdin.',
            $logger->messagesAtLevel(LogLevel::INFO),
        );
    }

    public function testLoggerEmitsInfoOnCloseFromIdleExactlyOnce(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer(), $logger);

        $transport->close();
        $transport->close();

        $matches = $logger->recordsMatching(LogLevel::INFO, 'Stdio transport closing from {priorState} state.');
        self::assertCount(1, $matches);
        self::assertSame(['priorState' => 'Idle'], $matches[0]['context']);

        $closed = $logger->recordsMatching(LogLevel::INFO, 'Stdio transport closed.');
        self::assertCount(1, $closed);
        self::assertSame([], $closed[0]['context']);
    }

    public function testLoggerEmitsInfoOnCloseFromRunning(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer(), $logger);

        $transport->start();
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::INFO, 'Stdio transport closing from {priorState} state.');
        self::assertCount(1, $matches);
        self::assertSame(['priorState' => 'Running'], $matches[0]['context']);
    }

    /**
     * @param array<string, mixed> $expectedContext
     */
    #[DataProvider('provideLoggerEmitsErrorOnSendFailureCases')]
    public function testLoggerEmitsErrorOnSendFailure(JsonRpcMessage $message, string $template, array $expectedContext): void
    {
        $logger = new ArrayLogger();
        $boom = new \RuntimeException('stdout exploded');
        $readable = new ReadableIterableStream(new \ArrayIterator([]));
        $writable = new ThrowingWritableStream($boom);
        $transport = new StdioServerTransport($readable, $writable, $logger);

        $transport->start();

        try {
            $transport->send($message);
        } catch (\Throwable) {
        }

        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::ERROR, $template);
        self::assertCount(1, $matches);
        self::assertSame(['exception' => $boom] + $expectedContext, $matches[0]['context']);
    }

    /**
     * @return iterable<string, array{JsonRpcMessage, string, array<string, mixed>}>
     */
    public static function provideLoggerEmitsErrorOnSendFailureCases(): iterable
    {
        yield 'request' => [
            new PingRequest(new RequestId(42)),
            'Stdio transport failed to send "{method}" request with ID "{id}". Closing.',
            ['method' => 'ping', 'id' => 42],
        ];

        yield 'notification' => [
            new CancelledNotification(new CancelledNotificationParams(new RequestId(7))),
            'Stdio transport failed to send "{method}" notification. Closing.',
            ['method' => 'notifications/cancelled'],
        ];

        yield 'result response' => [
            new JsonRpcResultResponse(new RequestId(99), new EmptyResult()),
            'Stdio transport failed to send result response for request ID "{id}". Closing.',
            ['id' => 99],
        ];

        yield 'error response with id' => [
            new JsonRpcErrorResponse(new RequestId(5), new InvalidParamsError('bad params')),
            'Stdio transport failed to send an error response for request ID "{id}". Closing.',
            ['id' => 5],
        ];

        yield 'error response with no id' => [
            new JsonRpcErrorResponse(null, new ParseError('unparsable')),
            'Stdio transport failed to send an error response with no correlatable ID. Closing.',
            [],
        ];
    }

    public function testLoggerEmitsErrorOnReadLoopFailure(): void
    {
        $logger = new ArrayLogger();
        $boom = new \RuntimeException('stdin exploded');
        $transport = new StdioServerTransport(new ThrowingReadableStream($boom), new WritableBuffer(), $logger);

        $transport->start();
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Stdio transport read loop failed. Closing.');
        self::assertCount(1, $matches);
        self::assertArrayHasKey('exception', $matches[0]['context']);
        self::assertSame($boom, $matches[0]['context']['exception']);
    }

    public function testLoggerEmitsWarningOnNonObjectEnvelope(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(
            new ReadableIterableStream(new \ArrayIterator(["[1,2,3]\n"])),
            new WritableBuffer(),
            $logger,
        );

        $transport->start();
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Stdio transport rejected a non-object envelope.');
        self::assertCount(1, $matches);
        self::assertArrayHasKey('exception', $matches[0]['context']);
        self::assertInstanceOf(\InvalidArgumentException::class, $matches[0]['context']['exception']);
    }

    public function testLoggerEmitsDebugOnMessageListenerLifecycle(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer(), $logger);

        $subscription = $transport->onMessage(static function (array $envelope): void {});
        $subscription->dispose();

        $registered = $logger->recordsMatching(LogLevel::DEBUG, 'Stdio transport registered a message listener. {count} active.');
        self::assertCount(1, $registered);
        self::assertSame(['count' => 1], $registered[0]['context']);

        $disposed = $logger->recordsMatching(LogLevel::DEBUG, 'Stdio transport disposed a message listener. {count} active.');
        self::assertCount(1, $disposed);
        self::assertSame(['count' => 0], $disposed[0]['context']);
    }

    public function testLoggerEmitsDebugOnCloseListenerLifecycle(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer(), $logger);

        $subscription = $transport->onClose(static function (): void {});
        $subscription->dispose();

        $registered = $logger->recordsMatching(LogLevel::DEBUG, 'Stdio transport registered a close listener. {count} active.');
        self::assertCount(1, $registered);
        self::assertSame(['count' => 1], $registered[0]['context']);

        $disposed = $logger->recordsMatching(LogLevel::DEBUG, 'Stdio transport disposed a close listener. {count} active.');
        self::assertCount(1, $disposed);
        self::assertSame(['count' => 0], $disposed[0]['context']);
    }

    public function testLoggerEmitsDebugOnErrorListenerLifecycle(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer(), $logger);

        $subscription = $transport->onError(static function (\Throwable $e): void {});
        $subscription->dispose();

        $registered = $logger->recordsMatching(LogLevel::DEBUG, 'Stdio transport registered an error listener. {count} active.');
        self::assertCount(1, $registered);
        self::assertSame(['count' => 1], $registered[0]['context']);

        $disposed = $logger->recordsMatching(LogLevel::DEBUG, 'Stdio transport disposed an error listener. {count} active.');
        self::assertCount(1, $disposed);
        self::assertSame(['count' => 0], $disposed[0]['context']);
    }

    public function testLoggerEmitsDebugOnDispatchedEnvelope(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(
            new ReadableIterableStream(new \ArrayIterator(['{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n"])),
            new WritableBuffer(),
            $logger,
        );

        $transport->start();
        EventLoop::run();

        self::assertContains(
            'Stdio transport received a JSON-RPC envelope.',
            $logger->messagesAtLevel(LogLevel::DEBUG),
        );
    }

    /**
     * @param array<string, mixed> $expectedContext
     */
    #[DataProvider('provideLoggerEmitsDebugOnSendSuccessCases')]
    public function testLoggerEmitsDebugOnSendSuccess(JsonRpcMessage $message, string $template, array $expectedContext): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer(), $logger);

        $transport->start();
        $transport->send($message);
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::DEBUG, $template);
        self::assertCount(1, $matches);
        self::assertSame($expectedContext, $matches[0]['context']);
    }

    /**
     * @return iterable<string, array{JsonRpcMessage, string, array<string, mixed>}>
     */
    public static function provideLoggerEmitsDebugOnSendSuccessCases(): iterable
    {
        yield 'request' => [
            new PingRequest(new RequestId(42)),
            'Stdio transport sent "{method}" request with ID "{id}".',
            ['method' => 'ping', 'id' => 42],
        ];

        yield 'notification' => [
            new CancelledNotification(new CancelledNotificationParams(new RequestId(7))),
            'Stdio transport sent "{method}" notification.',
            ['method' => 'notifications/cancelled'],
        ];

        yield 'result response' => [
            new JsonRpcResultResponse(new RequestId(99), new EmptyResult()),
            'Stdio transport sent a result response for request ID "{id}".',
            ['id' => 99],
        ];

        yield 'error response with id' => [
            new JsonRpcErrorResponse(new RequestId(5), new InvalidParamsError('bad params')),
            'Stdio transport sent an error response for request ID "{id}".',
            ['id' => 5],
        ];

        yield 'error response with no id' => [
            new JsonRpcErrorResponse(null, new ParseError('unparsable')),
            'Stdio transport sent an error response with no correlatable ID.',
            [],
        ];
    }

    public function testLoggerEmitsWarningOnMalformedJsonWithExceptionContext(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(
            new ReadableIterableStream(new \ArrayIterator(["{not json}\n"])),
            new WritableBuffer(),
            $logger,
        );

        $transport->start();
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Stdio transport rejected malformed JSON line.');

        self::assertCount(1, $matches);
        self::assertArrayHasKey('exception', $matches[0]['context']);
        self::assertInstanceOf(\JsonException::class, $matches[0]['context']['exception']);
    }

    public function testListenerRegistrationsCompose(): void
    {
        $transport = self::buildTransportReading(['{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n"]);

        $first = [];
        $second = [];
        $transport->onMessage(static function (array $envelope) use (&$first): void {
            $first[] = $envelope;
        });
        $transport->onMessage(static function (array $envelope) use (&$second): void {
            $second[] = $envelope;
        });

        $transport->start();
        EventLoop::run();

        self::assertCount(1, $first);
        self::assertCount(1, $second);
    }

    public function testDisposedMessageListenerDoesNotFire(): void
    {
        $transport = self::buildTransportReading(['{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n"]);

        $fired = false;
        $subscription = $transport->onMessage(static function (array $envelope) use (&$fired): void {
            $fired = true;
        });
        $subscription->dispose();

        $transport->start();
        EventLoop::run();

        self::assertFalse($fired);
    }

    public function testDisposedCloseListenerDoesNotFire(): void
    {
        $transport = new StdioServerTransport(new ReadableBuffer(''), new WritableBuffer());

        $fired = false;
        $subscription = $transport->onClose(static function () use (&$fired): void {
            $fired = true;
        });
        $subscription->dispose();

        $transport->close();

        self::assertFalse($fired);
    }

    public function testDisposedErrorListenerDoesNotFire(): void
    {
        $boom = new \RuntimeException('stdin exploded');
        $transport = new StdioServerTransport(new ThrowingReadableStream($boom), new WritableBuffer());

        $fired = false;
        $subscription = $transport->onError(static function () use (&$fired): void {
            $fired = true;
        });
        $subscription->dispose();

        $transport->start();
        EventLoop::run();

        self::assertFalse($fired);
    }

    public function testDrainListenerFiresBeforeCloseListenerWhenStreamEofs(): void
    {
        $transport = self::buildTransportReading([]);
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

    public function testDrainListenerThrowAbortsChainButCloseStillRuns(): void
    {
        $transport = self::buildTransportReading([]);
        $secondDrainFired = false;
        $closed = false;
        $transport->onDrain(static function (): void {
            throw new \RuntimeException('drain listener boom');
        });
        $transport->onDrain(static function () use (&$secondDrainFired): void {
            $secondDrainFired = true;
        });
        $transport->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        $caught = null;
        $previousHandler = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static function (\Throwable $e) use (&$caught): void {
            $caught = $e;
        });

        try {
            $transport->start();
            EventLoop::run();
        } finally {
            EventLoop::setErrorHandler($previousHandler);
        }

        self::assertFalse($secondDrainFired);
        self::assertTrue($closed);
        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertSame('drain listener boom', $caught->getMessage());
    }

    public function testDisposedDrainListenerDoesNotFire(): void
    {
        $transport = self::buildTransportReading([]);
        $fired = false;
        $subscription = $transport->onDrain(static function () use (&$fired): void {
            $fired = true;
        });
        $subscription->dispose();

        $transport->start();
        EventLoop::run();

        self::assertFalse($fired);
    }

    public function testLoggerEmitsDebugOnDrainListenerLifecycle(): void
    {
        $logger = new ArrayLogger();
        $transport = new StdioServerTransport(
            new ReadableIterableStream(new \ArrayIterator([])),
            new WritableBuffer(),
            $logger,
        );

        $subscription = $transport->onDrain(static function (): void {});
        $subscription->dispose();

        $registered = $logger->recordsMatching(LogLevel::DEBUG, 'Stdio transport registered a drain listener. {count} active.');
        $disposed = $logger->recordsMatching(LogLevel::DEBUG, 'Stdio transport disposed a drain listener. {count} active.');
        self::assertCount(1, $registered);
        self::assertCount(1, $disposed);
        self::assertSame(1, $registered[0]['context']['count'] ?? null);
        self::assertSame(0, $disposed[0]['context']['count'] ?? null);
    }

    public function testDisposingOneListenerLeavesOthers(): void
    {
        $transport = self::buildTransportReading(['{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n"]);

        $firstFired = false;
        $secondFired = false;
        $firstSubscription = $transport->onMessage(static function (array $envelope) use (&$firstFired): void {
            $firstFired = true;
        });
        $transport->onMessage(static function (array $envelope) use (&$secondFired): void {
            $secondFired = true;
        });
        $firstSubscription->dispose();

        $transport->start();
        EventLoop::run();

        self::assertFalse($firstFired);
        self::assertTrue($secondFired);
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

    /**
     * @param list<array<string, mixed>> $envelopes
     */
    private static function captureEnvelopesInto(StdioServerTransport $transport, array &$envelopes): void
    {
        $transport->onMessage(static function (array $envelope) use (&$envelopes): void {
            $envelopes[] = $envelope;
        });
    }

    /**
     * @param list<\Throwable> $errors
     */
    private static function captureErrorsInto(StdioServerTransport $transport, array &$errors): void
    {
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });
    }

    private static function countClosesInto(StdioServerTransport $transport, int &$count): void
    {
        $transport->onClose(static function () use (&$count): void {
            ++$count;
        });
    }
}
