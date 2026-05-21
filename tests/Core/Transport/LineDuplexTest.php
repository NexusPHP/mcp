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
use Amp\ByteStream\WritableBuffer;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\CancelledNotificationParams;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Transport\LineDuplex;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\ThrowingReadableStream;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\ThrowingWritableStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

/**
 * @internal
 */
#[CoversClass(LineDuplex::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class LineDuplexTest extends TestCase
{
    private ?LineDuplex $duplexUnderConcurrentClose = null;

    public function testStartTransitionsFromIdleToRunningAndLogsStart(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $duplex->start(new ReadableBuffer(''), new WritableBuffer());
        EventLoop::run();

        self::assertContains('demo transport started.', $logger->messagesAtLevel(LogLevel::INFO));
    }

    public function testStartAfterRunningThrowsHostTransportAlreadyStarted(): void
    {
        $duplex = self::buildDuplex();
        $duplex->start(new ReadableBuffer(''), new WritableBuffer());

        $this->expectException(TransportAlreadyStartedException::class);
        $this->expectExceptionMessage(\sprintf('%s has already been started.', self::class));

        try {
            $duplex->start(new ReadableBuffer(''), new WritableBuffer());
        } finally {
            EventLoop::run();
        }
    }

    public function testStartAfterCloseThrowsTransportAlreadyClosed(): void
    {
        $duplex = self::buildDuplex();
        $duplex->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $duplex->start(new ReadableBuffer(''), new WritableBuffer());
    }

    public function testSendBeforeStartThrowsTransportNotStarted(): void
    {
        $duplex = self::buildDuplex();

        $this->expectException(TransportNotStartedException::class);

        $duplex->send(new PingRequest(new RequestId(1)));
    }

    public function testSendAfterCloseThrowsTransportAlreadyClosed(): void
    {
        $duplex = self::buildDuplex();
        $duplex->start(new ReadableBuffer(''), new WritableBuffer());
        $duplex->close();
        EventLoop::run();

        $this->expectException(TransportAlreadyClosedException::class);

        $duplex->send(new PingRequest(new RequestId(1)));
    }

    public function testCloseFromIdleIsIdempotent(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $duplex->close();
        $duplex->close();

        $matches = $logger->recordsMatching(LogLevel::INFO, 'demo transport closed.');
        self::assertCount(1, $matches);
    }

    public function testOnBeforeCloseFiresExactlyOnceDuringClose(): void
    {
        $disposeCalls = 0;
        $duplex = self::buildDuplex(
            onBeforeClose: static function () use (&$disposeCalls): void {
                ++$disposeCalls;
            },
        );
        $duplex->start(new ReadableBuffer(''), new WritableBuffer());
        EventLoop::run();

        $duplex->close();
        $duplex->close();

        self::assertSame(1, $disposeCalls);
    }

    public function testLoggerEmitsInfoOnCloseFromIdleWithIdlePriorState(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $duplex->close();
        $duplex->close();

        $matches = $logger->recordsMatching(LogLevel::INFO, 'demo transport closing from {priorState} state.');
        self::assertCount(1, $matches);
        self::assertSame(['priorState' => 'Idle'], $matches[0]['context']);

        $closed = $logger->recordsMatching(LogLevel::INFO, 'demo transport closed.');
        self::assertCount(1, $closed);
        self::assertSame([], $closed[0]['context']);
    }

    public function testLoggerEmitsInfoOnCloseFromRunningWithRunningPriorState(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $duplex->start(new ReadableBuffer(''), new WritableBuffer());
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::INFO, 'demo transport closing from {priorState} state.');
        self::assertCount(1, $matches);
        self::assertSame(['priorState' => 'Running'], $matches[0]['context']);
    }

    public function testCloseEmitsCloseListenersExactlyOnce(): void
    {
        $closes = 0;
        $duplex = self::buildDuplex();
        $duplex->onClose(static function () use (&$closes): void {
            ++$closes;
        });

        $duplex->start(new ReadableBuffer(''), new WritableBuffer());
        EventLoop::run();
        $duplex->close();
        $duplex->close();

        self::assertSame(1, $closes);
    }

    public function testReadLoopFiresDrainBeforeCloseOnEof(): void
    {
        $events = [];
        $duplex = self::buildDuplex();
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $duplex->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $duplex->start(new ReadableBuffer(''), new WritableBuffer());
        EventLoop::run();

        self::assertSame(['drain', 'close'], $events);
    }

    public function testCloseFiresEvenWhenDrainListenerThrows(): void
    {
        $previousHandler = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static function (): void {});

        try {
            $duplex = self::buildDuplex();
            $secondDrainFired = false;
            $closed = false;
            $duplex->onDrain(static function (): void {
                throw new \RuntimeException('drain listener boom');
            });
            $duplex->onDrain(static function () use (&$secondDrainFired): void {
                $secondDrainFired = true;
            });
            $duplex->onClose(static function () use (&$closed): void {
                $closed = true;
            });

            $duplex->start(new ReadableBuffer(''), new WritableBuffer());
            EventLoop::run();

            self::assertFalse($secondDrainFired);
            self::assertTrue($closed);
        } finally {
            EventLoop::setErrorHandler($previousHandler);
        }
    }

    public function testCloseFiresEvenWhenErrorListenerThrows(): void
    {
        $previousHandler = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static function (): void {});

        try {
            $duplex = self::buildDuplex();

            $closes = 0;
            $duplex->onError(static function (): void {
                throw new \RuntimeException('error listener boom');
            });
            $duplex->onClose(static function () use (&$closes): void {
                ++$closes;
            });

            $duplex->start(new ThrowingReadableStream(new \RuntimeException('stdin boom')), new WritableBuffer());
            EventLoop::run();

            self::assertSame(1, $closes);
        } finally {
            EventLoop::setErrorHandler($previousHandler);
        }
    }

    public function testReportsParseFailureWithParseErrorEnvelopeForMalformedJson(): void
    {
        $reported = [];
        $duplex = self::buildDuplex(
            onParseFailure: static function (JsonRpcErrorResponse $response) use (&$reported): void {
                $reported[] = $response;
            },
        );
        $duplex->start(
            new ReadableIterableStream(new \ArrayIterator(["{not json}\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        self::assertCount(1, $reported);
        self::assertSame(-32700, $reported[0]->error->code, 'Malformed JSON must surface a ParseError (-32700).');
    }

    public function testReportsParseFailureWithInvalidRequestErrorForNonObjectEnvelope(): void
    {
        $reported = [];
        $duplex = self::buildDuplex(
            onParseFailure: static function (JsonRpcErrorResponse $response) use (&$reported): void {
                $reported[] = $response;
            },
        );
        $duplex->start(
            new ReadableIterableStream(new \ArrayIterator(["[1,2,3]\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        self::assertCount(1, $reported);
        self::assertSame(-32600, $reported[0]->error->code, 'Non-object envelope must surface an InvalidRequestError (-32600).');
    }

    public function testParseFailureIsSilentWhenNoOnParseFailureClosureIsConfigured(): void
    {
        $duplex = self::buildDuplex();

        $duplex->start(
            new ReadableIterableStream(new \ArrayIterator(["{not json}\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        $this->expectNotToPerformAssertions();
    }

    public function testNonObjectEnvelopeFiresErrorListenerAndReportsParseFailure(): void
    {
        $errors = [];
        $reported = [];
        $duplex = self::buildDuplex(
            onParseFailure: static function (JsonRpcErrorResponse $response) use (&$reported): void {
                $reported[] = $response;
            },
        );
        $duplex->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });
        $duplex->start(
            new ReadableIterableStream(new \ArrayIterator(["[1,2,3]\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        self::assertCount(1, $errors);
        self::assertInstanceOf(\InvalidArgumentException::class, $errors[0]);
        self::assertCount(1, $reported);
    }

    public function testMessageListenerThrowFiresErrorListener(): void
    {
        $boom = new \RuntimeException('listener boom');
        $errors = [];
        $duplex = self::buildDuplex();
        $duplex->onMessage(static function (array $envelope) use ($boom): void {
            throw $boom;
        });
        $duplex->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $duplex->start(
            new ReadableIterableStream(new \ArrayIterator(['{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        self::assertSame([$boom], $errors);
    }

    public function testSendWritesSerializedEnvelopeFollowedByNewline(): void
    {
        $writable = new WritableBuffer();
        $duplex = self::buildDuplex();

        $duplex->start(new ReadableBuffer(''), $writable);
        $duplex->send(new PingRequest(new RequestId(99)));
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
        $duplex = self::buildDuplex();

        $duplex->start(new ReadableBuffer(''), $writable);
        $duplex->send(new CancelledNotification(new CancelledNotificationParams(
            new RequestId(1),
            'café/done',
        )));
        EventLoop::run();
        $writable->close();

        $output = $writable->buffer();
        self::assertStringContainsString('notifications/cancelled', $output);
        self::assertStringContainsString('café/done', $output);
    }

    #[DataProvider('provideLoggerEmitsDebugOnSendSuccessCases')]
    public function testLoggerEmitsDebugOnSendSuccess(JsonRpcMessage $message, string $expectedKind): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $duplex->start(new ReadableBuffer(''), new WritableBuffer());
        $duplex->send($message);
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::DEBUG, 'demo transport sent {kind}.');
        self::assertCount(1, $matches);
        self::assertSame(['kind' => $expectedKind], $matches[0]['context']);
    }

    /**
     * @return iterable<string, array{JsonRpcMessage, string}>
     */
    public static function provideLoggerEmitsDebugOnSendSuccessCases(): iterable
    {
        yield 'request' => [
            new PingRequest(new RequestId(42)),
            '"ping" request with id "42"',
        ];

        yield 'notification' => [
            new CancelledNotification(new CancelledNotificationParams(new RequestId(7))),
            '"notifications/cancelled" notification',
        ];

        yield 'response envelope' => [
            new JsonRpcResultResponse(new RequestId(99), new EmptyResult()),
            'a response envelope',
        ];
    }

    public function testSendFailureClosesSynchronouslyAndRethrows(): void
    {
        $boom = new \RuntimeException('writable boom');
        $closes = 0;
        $duplex = self::buildDuplex();
        $duplex->onClose(static function () use (&$closes): void {
            ++$closes;
        });

        $duplex->start(new ReadableBuffer(''), new ThrowingWritableStream($boom));

        $closesBeforeSend = $closes;

        try {
            $duplex->send(new PingRequest(new RequestId(1)));
            self::fail('Expected send() to rethrow the underlying write failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($boom, $caught);
        }

        $closesAfterSend = $closes;

        EventLoop::run();

        self::assertSame(0, $closesBeforeSend);
        self::assertSame(
            1,
            $closesAfterSend,
            'Send failure must close synchronously, before the event loop is pumped.',
        );
    }

    #[DataProvider('provideLoggerEmitsErrorOnSendFailureCases')]
    public function testLoggerEmitsErrorOnSendFailure(JsonRpcMessage $message, string $expectedKind): void
    {
        $boom = new \RuntimeException('writable boom');
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $duplex->start(new ReadableBuffer(''), new ThrowingWritableStream($boom));

        try {
            $duplex->send($message);
        } catch (\Throwable) {
        }

        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'demo transport failed to send {kind}. Closing.');
        self::assertCount(1, $matches);
        self::assertSame(['kind' => $expectedKind, 'exception' => $boom], $matches[0]['context']);
    }

    /**
     * @return iterable<string, array{JsonRpcMessage, string}>
     */
    public static function provideLoggerEmitsErrorOnSendFailureCases(): iterable
    {
        yield 'request' => [
            new PingRequest(new RequestId(42)),
            '"ping" request with id "42"',
        ];

        yield 'notification' => [
            new CancelledNotification(new CancelledNotificationParams(new RequestId(7))),
            '"notifications/cancelled" notification',
        ];

        yield 'response envelope' => [
            new JsonRpcResultResponse(new RequestId(99), new EmptyResult()),
            'a response envelope',
        ];
    }

    public function testSendDuringConcurrentCloseWrapsByteStreamFailureInTransportAlreadyClosedException(): void
    {
        $boom = new \RuntimeException('writable was concurrently closed');
        $logger = new ArrayLogger();
        $writable = new ThrowingWritableStream(
            $boom,
            beforeThrow: function (): void {
                $sut = $this->duplexUnderConcurrentClose;

                if (! $sut instanceof LineDuplex) {
                    throw new \LogicException('Duplex reference must be attached before this callback fires.');
                }

                $sut->close();
            },
        );
        $this->duplexUnderConcurrentClose = self::buildDuplex(logger: $logger);
        $this->duplexUnderConcurrentClose->start(new ReadableBuffer(''), $writable);

        try {
            $this->duplexUnderConcurrentClose->send(new PingRequest(new RequestId(1)));
            self::fail('Expected send() to throw on the concurrent close.');
        } catch (TransportAlreadyClosedException $caught) {
            self::assertSame('Cannot send on a closed transport.', $caught->getMessage());
            self::assertSame($boom, $caught->getPrevious());
        }

        EventLoop::run();

        self::assertCount(
            0,
            $logger->messagesAtLevel(LogLevel::ERROR),
            'Concurrent-close path must not log at ERROR. ERROR is reserved for genuine send failures.',
        );
    }

    #[DataProvider('provideLoggerEmitsDebugOnConcurrentCloseSkippedSendCases')]
    public function testLoggerEmitsDebugOnConcurrentCloseSkippedSend(JsonRpcMessage $message, string $expectedKind): void
    {
        $boom = new \RuntimeException('writable was concurrently closed');
        $logger = new ArrayLogger();
        $writable = new ThrowingWritableStream(
            $boom,
            beforeThrow: function (): void {
                $sut = $this->duplexUnderConcurrentClose;

                if (! $sut instanceof LineDuplex) {
                    throw new \LogicException('Duplex reference must be attached before this callback fires.');
                }

                $sut->close();
            },
        );
        $this->duplexUnderConcurrentClose = self::buildDuplex(logger: $logger);
        $this->duplexUnderConcurrentClose->start(new ReadableBuffer(''), $writable);

        try {
            $this->duplexUnderConcurrentClose->send($message);
        } catch (TransportAlreadyClosedException) {
        }

        EventLoop::run();

        $matches = $logger->recordsMatching(
            LogLevel::DEBUG,
            'demo transport skipped sending {kind}. Transport was concurrently closed.',
        );
        self::assertCount(1, $matches);
        self::assertSame(['kind' => $expectedKind, 'exception' => $boom], $matches[0]['context']);
    }

    /**
     * @return iterable<string, array{JsonRpcMessage, string}>
     */
    public static function provideLoggerEmitsDebugOnConcurrentCloseSkippedSendCases(): iterable
    {
        yield 'request' => [
            new PingRequest(new RequestId(42)),
            '"ping" request with id "42"',
        ];

        yield 'notification' => [
            new CancelledNotification(new CancelledNotificationParams(new RequestId(7))),
            '"notifications/cancelled" notification',
        ];

        yield 'response envelope' => [
            new JsonRpcResultResponse(new RequestId(99), new EmptyResult()),
            'a response envelope',
        ];
    }

    public function testReadLoopFailureLogsErrorAndEmitsErrorListener(): void
    {
        $boom = new \RuntimeException('stdin boom');
        $logger = new ArrayLogger();
        $errors = [];
        $duplex = self::buildDuplex(logger: $logger);
        $duplex->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $duplex->start(new ThrowingReadableStream($boom), new WritableBuffer());
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'demo transport read loop failed. Closing.');
        self::assertCount(1, $matches);
        self::assertSame(['exception' => $boom], $matches[0]['context']);
        self::assertSame([$boom], $errors);
    }

    public function testLoggerEmitsWarningWithExceptionContextOnMalformedJson(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $duplex->start(
            new ReadableIterableStream(new \ArrayIterator(["{not json}\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'demo transport rejected malformed JSON line.');
        self::assertCount(1, $matches);
        self::assertArrayHasKey('exception', $matches[0]['context']);
        self::assertInstanceOf(\JsonException::class, $matches[0]['context']['exception']);
    }

    public function testLoggerEmitsWarningWithExceptionContextOnNonObjectEnvelope(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $duplex->start(
            new ReadableIterableStream(new \ArrayIterator(["[1,2,3]\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'demo transport rejected a non-object envelope.');
        self::assertCount(1, $matches);
        self::assertArrayHasKey('exception', $matches[0]['context']);
        self::assertInstanceOf(\InvalidArgumentException::class, $matches[0]['context']['exception']);
    }

    public function testLoggerEmitsDebugOnDispatchedEnvelope(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $duplex->start(
            new ReadableIterableStream(new \ArrayIterator(['{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        self::assertContains(
            'demo transport received a JSON-RPC envelope.',
            $logger->messagesAtLevel(LogLevel::DEBUG),
        );
    }

    public function testDispatchedEnvelopeFiresMessageListener(): void
    {
        $envelopes = [];
        $duplex = self::buildDuplex();
        $duplex->onMessage(static function (array $envelope) use (&$envelopes): void {
            $envelopes[] = $envelope;
        });

        $duplex->start(
            new ReadableIterableStream(new \ArrayIterator(['{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        self::assertSame(
            [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']],
            $envelopes,
        );
    }

    public function testForwardLinesDeliversEachDecodedLineToTheClosure(): void
    {
        $lines = [];
        $duplex = self::buildDuplex();
        $duplex->forwardLines(
            new ReadableIterableStream(new \ArrayIterator(["one\ntwo\n"])),
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );
        EventLoop::run();

        self::assertSame(['one', 'two'], $lines);
    }

    public function testForwardLinesFailureLogsWarningWithExceptionContext(): void
    {
        $boom = new \RuntimeException('side-channel boom');
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $duplex->forwardLines(
            new ThrowingReadableStream($boom),
            static function (): void {},
        );
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'demo transport side-channel loop failed.');
        self::assertCount(1, $matches);
        self::assertSame(['exception' => $boom], $matches[0]['context']);
    }

    public function testOnMessageRegistrationLogsDebugWithCorrectVerbArticleAndCount(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $subscription = $duplex->onMessage(static function (): void {});

        $registered = $logger->recordsMatching(LogLevel::DEBUG, 'demo transport registered a message listener. {count} active.');
        self::assertCount(1, $registered);
        self::assertSame(['count' => 1], $registered[0]['context']);

        $subscription->dispose();

        $disposed = $logger->recordsMatching(LogLevel::DEBUG, 'demo transport disposed a message listener. {count} active.');
        self::assertCount(1, $disposed);
        self::assertSame(['count' => 0], $disposed[0]['context']);
    }

    public function testOnDrainRegistrationLogsDebugWithCorrectVerbArticleAndCount(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $subscription = $duplex->onDrain(static function (): void {});

        $registered = $logger->recordsMatching(LogLevel::DEBUG, 'demo transport registered a drain listener. {count} active.');
        self::assertCount(1, $registered);
        self::assertSame(['count' => 1], $registered[0]['context']);

        $subscription->dispose();

        $disposed = $logger->recordsMatching(LogLevel::DEBUG, 'demo transport disposed a drain listener. {count} active.');
        self::assertCount(1, $disposed);
        self::assertSame(['count' => 0], $disposed[0]['context']);
    }

    public function testOnCloseRegistrationLogsDebugWithCorrectVerbArticleAndCount(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $subscription = $duplex->onClose(static function (): void {});

        $registered = $logger->recordsMatching(LogLevel::DEBUG, 'demo transport registered a close listener. {count} active.');
        self::assertCount(1, $registered);
        self::assertSame(['count' => 1], $registered[0]['context']);

        $subscription->dispose();

        $disposed = $logger->recordsMatching(LogLevel::DEBUG, 'demo transport disposed a close listener. {count} active.');
        self::assertCount(1, $disposed);
        self::assertSame(['count' => 0], $disposed[0]['context']);
    }

    public function testOnErrorRegistrationUsesTheAnArticle(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $subscription = $duplex->onError(static function (): void {});

        $registered = $logger->recordsMatching(LogLevel::DEBUG, 'demo transport registered an error listener. {count} active.');
        self::assertCount(1, $registered);
        self::assertSame(['count' => 1], $registered[0]['context']);

        $subscription->dispose();

        $disposed = $logger->recordsMatching(LogLevel::DEBUG, 'demo transport disposed an error listener. {count} active.');
        self::assertCount(1, $disposed);
        self::assertSame(['count' => 0], $disposed[0]['context']);
    }

    /**
     * @param null|\Closure(JsonRpcErrorResponse): void $onParseFailure
     * @param null|\Closure(): void                     $onBeforeClose
     */
    private static function buildDuplex(
        ?ArrayLogger $logger = null,
        ?\Closure $onParseFailure = null,
        ?\Closure $onBeforeClose = null,
    ): LineDuplex {
        return new LineDuplex(
            hostTransport: self::class,
            label: 'demo',
            logger: $logger ?? new NullLogger(),
            onParseFailure: $onParseFailure,
            onBeforeClose: $onBeforeClose,
        );
    }
}
