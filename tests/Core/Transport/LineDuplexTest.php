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

use Amp\ByteStream\Pipe;
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
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

use function Amp\delay;

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

        $matches = $logger->recordsMatching(LogLevel::INFO, '{label} transport started.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'demo'], $matches[0]['context']);
    }

    public function testStartAfterRunningThrowsHostTransportAlreadyStarted(): void
    {
        $duplex = self::buildDuplex();
        $duplex->start(new ReadableBuffer(''), new WritableBuffer());

        $this->expectException(TransportAlreadyStartedException::class);
        $this->expectExceptionMessageIs(\sprintf('%s has already been started.', self::class));

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

        $matches = $logger->recordsMatching(LogLevel::INFO, '{label} transport closed.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'demo'], $matches[0]['context']);
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

        $matches = $logger->recordsMatching(LogLevel::INFO, '{label} transport closing from {priorState} state.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'demo', 'priorState' => 'Idle'], $matches[0]['context']);

        $closed = $logger->recordsMatching(LogLevel::INFO, '{label} transport closed.');
        self::assertCount(1, $closed);
        self::assertSame(['label' => 'demo'], $closed[0]['context']);
    }

    public function testLoggerEmitsInfoOnCloseFromRunningWithRunningPriorState(): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $duplex->start(new ReadableBuffer(''), new WritableBuffer());
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::INFO, '{label} transport closing from {priorState} state.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'demo', 'priorState' => 'Running'], $matches[0]['context']);
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

    public function testCloseFromMainFiberDrainsSuspendedReadLoopBeforeReturning(): void
    {
        $pipe = new Pipe(8192);
        $sink = $pipe->getSink();
        $events = [];
        $duplex = self::buildDuplex(
            onBeforeClose: static function () use ($sink): void {
                $sink->close();
            },
        );
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $duplex->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $duplex->start($pipe->getSource(), new WritableBuffer());

        // Let the read loop start and park on its still-open source read.
        delay(0.01);

        // close() must run the parked read loop to completion (firing drain)
        // before it emits close, so no fiber is left suspended on the read.
        $duplex->close();

        self::assertSame(['drain', 'close'], $events);
    }

    public function testCloseDrainsRegisteredSideChannelLoopBeforeReturning(): void
    {
        $pipe = new Pipe(8192);
        $sink = $pipe->getSink();
        $lines = [];
        $duplex = self::buildDuplex(
            onBeforeClose: static function () use ($sink): void {
                $sink->write("tail\n");
                $sink->close();
            },
        );
        $duplex->forwardLines(
            $pipe->getSource(),
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        // No start(), so only the side-channel drain can pump the loop here.
        $duplex->close();

        self::assertSame(['tail'], $lines);
    }

    public function testCloseDrainsAParkedReadLoopWithoutAnOnBeforeClose(): void
    {
        $pipe = new Pipe(8192);
        $events = [];
        $errors = [];
        $duplex = self::buildDuplex();
        $duplex->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $duplex->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $duplex->start($pipe->getSource(), new WritableBuffer());

        // Let the read loop park on the still-open source. With no onBeforeClose,
        // only close() closing the readable itself can force the EOF the drain needs.
        delay(0.01);

        $duplex->close();

        self::assertSame(['drain', 'close'], $events);
        self::assertSame([], $errors, 'Closing the readable should EOF the read loop cleanly, not error.');
    }

    public function testSideChannelOnLineCallingCloseDoesNotSelfDeadlock(): void
    {
        $pipe = new Pipe(8192);
        $sink = $pipe->getSink();
        $sink->write("first\n");
        $sink->close();

        $closed = false;
        $duplex = self::buildDuplex();
        $duplex->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        $duplex->forwardLines(
            $pipe->getSource(),
            static function (string $line) use ($duplex): void {
                $duplex->close();
            },
        );

        EventLoop::run();

        self::assertTrue($closed, 'close() from inside a side-channel onLine must return, not await its own fiber.');
    }

    public function testCloseStillReturnsWhenSideChannelFailureLoggerThrows(): void
    {
        $previousHandler = EventLoop::getErrorHandler();
        EventLoop::setErrorHandler(static function (): void {});

        try {
            $logger = new class extends NullLogger {
                #[\Override]
                public function warning(string|\Stringable $message, array $context = []): void
                {
                    throw new \RuntimeException('logger boom during side-channel warning');
                }
            };
            $duplex = self::buildDuplex(logger: $logger);
            $duplex->forwardLines(
                new ThrowingReadableStream(new \RuntimeException('side-channel boom')),
                static function (): void {},
            );

            // The side-channel loop fails, then its failure logger throws too.
            // close() must still return: the completion fires in the `finally`,
            // so the drain await is not left hanging on it.
            $duplex->close();

            $this->expectNotToPerformAssertions();
        } finally {
            EventLoop::setErrorHandler($previousHandler);
        }
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
        $errors = [];
        $duplex = self::buildDuplex();
        $duplex->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $duplex->start(
            new ReadableIterableStream(new \ArrayIterator(["{not json}\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        self::assertSame([], $errors, 'A parse failure with no onParseFailure closure must not invoke a null callback.');
    }

    public function testNonObjectEnvelopeFiresErrorListenerAndReportsParseFailure(): void
    {
        $errors = [];
        $reported = [];
        $messages = [];
        $duplex = self::buildDuplex(
            onParseFailure: static function (JsonRpcErrorResponse $response) use (&$reported): void {
                $reported[] = $response;
            },
        );
        $duplex->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });
        $duplex->onMessage(static function (array $envelope) use (&$messages): void {
            $messages[] = $envelope;
        });
        $duplex->start(
            new ReadableIterableStream(new \ArrayIterator(["[1,2,3]\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        self::assertCount(1, $errors);
        self::assertInstanceOf(\InvalidArgumentException::class, $errors[0]);
        self::assertCount(1, $reported);
        self::assertSame([], $messages, 'A non-object envelope must not reach the message listeners.');
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

        $matches = $logger->recordsMatching(LogLevel::DEBUG, '{label} transport sent {kind}.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'demo', 'kind' => $expectedKind], $matches[0]['context']);
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

        $matches = $logger->recordsMatching(LogLevel::ERROR, '{label} transport failed to send {kind}. Closing.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'demo', 'kind' => $expectedKind, 'exception' => $boom], $matches[0]['context']);
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
            '{label} transport skipped sending {kind}. Transport was concurrently closed.',
        );
        self::assertCount(1, $matches);
        self::assertSame(
            ['label' => 'demo', 'kind' => $expectedKind, 'exception' => $boom],
            $matches[0]['context'],
        );
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

        $matches = $logger->recordsMatching(LogLevel::ERROR, '{label} transport read loop failed. Closing.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'demo', 'exception' => $boom], $matches[0]['context']);
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

        $matches = $logger->recordsMatching(LogLevel::WARNING, '{label} transport rejected malformed JSON line.');
        self::assertCount(1, $matches);
        self::assertSame('demo', $matches[0]['context']['label'] ?? null);
        self::assertInstanceOf(\JsonException::class, $matches[0]['context']['exception'] ?? null);
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

        $matches = $logger->recordsMatching(LogLevel::WARNING, '{label} transport rejected a non-object envelope.');
        self::assertCount(1, $matches);
        self::assertSame('demo', $matches[0]['context']['label'] ?? null);
        self::assertInstanceOf(\InvalidArgumentException::class, $matches[0]['context']['exception'] ?? null);
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

        $matches = $logger->recordsMatching(LogLevel::DEBUG, '{label} transport received a JSON-RPC envelope.');
        self::assertNotEmpty($matches);
        self::assertSame(['label' => 'demo'], $matches[0]['context']);
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

        $matches = $logger->recordsMatching(LogLevel::WARNING, '{label} transport side-channel loop failed.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'demo', 'exception' => $boom], $matches[0]['context']);
    }

    public function testForwardLinesPrunesItsBookkeepingOnceTheLoopEnds(): void
    {
        $duplex = self::buildDuplex();
        $duplex->forwardLines(new ReadableBuffer("line\n"), static function (): void {});

        EventLoop::run();

        // One-off use of reflection: pruning the side-channel bookkeeping is a
        // memory-leak fix with no behavioural observable (awaiting an already
        // completed future is a no-op), so there is nothing on the public surface
        // to assert. This is not a precedent for testing private state generally.
        $completions = new \ReflectionProperty(LineDuplex::class, 'sideChannelCompletions')->getValue($duplex);
        $fibers = new \ReflectionProperty(LineDuplex::class, 'sideChannelFibers')->getValue($duplex);

        self::assertSame([], $completions, 'A finished side channel must be removed from the completion list.');
        self::assertSame([], $fibers, 'A finished side channel must be removed from the fiber map.');
    }

    /**
     * @param 'close'|'drain'|'error'|'message' $kind
     * @param 'a'|'an'                          $article
     */
    #[DataProvider('provideListenerRegistrationLogsDebugWithCorrectVerbArticleAndCountCases')]
    public function testListenerRegistrationLogsDebugWithCorrectVerbArticleAndCount(string $kind, string $article): void
    {
        $logger = new ArrayLogger();
        $duplex = self::buildDuplex(logger: $logger);

        $subscription = match ($kind) {
            'message' => $duplex->onMessage(static function (): void {}),
            'error' => $duplex->onError(static function (): void {}),
            'drain' => $duplex->onDrain(static function (): void {}),
            'close' => $duplex->onClose(static function (): void {}),
        };
        $subscription->dispose();

        $records = $logger->recordsMatching(
            LogLevel::DEBUG,
            '{label} transport {verb} {article} {kind} listener. {count} active.',
        );
        self::assertCount(2, $records);
        self::assertSame(
            ['label' => 'demo', 'verb' => 'registered', 'article' => $article, 'kind' => $kind, 'count' => 1],
            $records[0]['context'],
        );
        self::assertSame(
            ['label' => 'demo', 'verb' => 'disposed', 'article' => $article, 'kind' => $kind, 'count' => 0],
            $records[1]['context'],
        );
    }

    /**
     * @return iterable<string, array{'close'|'drain'|'error'|'message', 'a'|'an'}>
     */
    public static function provideListenerRegistrationLogsDebugWithCorrectVerbArticleAndCountCases(): iterable
    {
        yield 'message' => ['message', 'a'];

        yield 'drain' => ['drain', 'a'];

        yield 'close' => ['close', 'a'];

        yield 'error' => ['error', 'an'];
    }

    /**
     * @param null|\Closure(JsonRpcErrorResponse): void $onParseFailure
     * @param null|\Closure(): void                     $onBeforeClose
     */
    private static function buildDuplex(
        ?LoggerInterface $logger = null,
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
