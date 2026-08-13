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
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\WritableBuffer;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\CancelledNotificationParams;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;
use Nexus\Mcp\Core\Transport\LineDuplex;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(LineDuplex::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class LineDuplexTest extends AbstractMcpTestCase
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

        $duplex->send(new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())));
    }

    public function testSendAfterCloseThrowsTransportAlreadyClosed(): void
    {
        $duplex = self::buildDuplex();
        $duplex->start(new ReadableBuffer(''), new WritableBuffer());
        $duplex->close();
        EventLoop::run();

        $this->expectException(TransportAlreadyClosedException::class);

        $duplex->send(new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())));
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

    public function testCloseFromIdleStillFiresDrainBeforeClose(): void
    {
        $events = [];
        $duplex = self::buildDuplex();
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $duplex->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $duplex->close();

        self::assertSame(['drain', 'close'], $events);
    }

    public function testCloseInTheSameTickAsStartStillDrainsBeforeReturning(): void
    {
        $events = [];
        $duplex = self::buildDuplex();
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $duplex->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $duplex->start(new ReadableBuffer("{}\n"), new WritableBuffer());
        $duplex->close();
        $events[] = 'returned';

        EventLoop::run();

        self::assertSame(['drain', 'close', 'returned'], $events);
    }

    public function testCloseBeforeTheReadLoopsFirstTurnStillDrainsItBeforeReturning(): void
    {
        $events = [];
        $source = new class implements \IteratorAggregate, ReadableStream {
            use ReadableStreamIteratorAggregate;

            /**
             * @var null|\Closure(): void
             */
            public ?\Closure $onRead = null;

            private bool $closed = false;

            #[\Override]
            public function read(?Cancellation $cancellation = null): ?string
            {
                if (null !== $this->onRead) {
                    ($this->onRead)();
                }

                return null;
            }

            #[\Override]
            public function isReadable(): bool
            {
                return ! $this->closed;
            }

            #[\Override]
            public function close(): void
            {
                $this->closed = true;
            }

            #[\Override]
            public function isClosed(): bool
            {
                return $this->closed;
            }

            #[\Override]
            public function onClose(\Closure $onClose): void
            {
            }
        };
        $source->onRead = static function () use (&$events): void {
            $events[] = 'read';
        };

        $duplex = self::buildDuplex();
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $duplex->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $duplex->start($source, new WritableBuffer());
        $duplex->close();
        $events[] = 'returned';

        EventLoop::run();

        self::assertSame(['read', 'drain', 'close', 'returned'], $events, 'A close before the read loop\'s first turn must still drain it before returning.');
    }

    public function testCloseFromAFiberDrainsAParkedReadLoopBeforeDrainListeners(): void
    {
        $events = [];

        /** @var DeferredFuture<null> $gate */
        $gate = new DeferredFuture();
        $source = new class ($gate) implements \IteratorAggregate, ReadableStream {
            use ReadableStreamIteratorAggregate;

            /**
             * @var null|\Closure(string): void
             */
            public ?\Closure $onEvent = null;

            private bool $closed = false;

            /**
             * @param DeferredFuture<null> $gate
             */
            public function __construct(private readonly DeferredFuture $gate)
            {
            }

            #[\Override]
            public function read(?Cancellation $cancellation = null): ?string
            {
                if (null !== $this->onEvent) {
                    ($this->onEvent)('read:start');
                }

                $this->gate->getFuture()->await();

                if (null !== $this->onEvent) {
                    ($this->onEvent)('read:eof');
                }

                return null;
            }

            #[\Override]
            public function isReadable(): bool
            {
                return ! $this->closed;
            }

            #[\Override]
            public function close(): void
            {
                $this->closed = true;

                if (! $this->gate->isComplete()) {
                    $this->gate->complete();
                }
            }

            #[\Override]
            public function isClosed(): bool
            {
                return $this->closed;
            }

            #[\Override]
            public function onClose(\Closure $onClose): void
            {
            }
        };
        $source->onEvent = static function (string $event) use (&$events): void {
            $events[] = $event;
        };

        $duplex = self::buildDuplex();
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $duplex->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $duplex->start($source, new WritableBuffer());
        delay(0.001);

        async(static function () use ($duplex): void { $duplex->close(); })->await();

        self::assertSame(
            ['read:start', 'read:eof', 'drain', 'close'],
            $events,
            'A close from a foreign fiber must drain the parked read loop before drain listeners run.',
        );
    }

    public function testCloseWaitsForAnInFlightMessageListenerBeforeDraining(): void
    {
        $pipe = new Pipe(8_192);
        $sink = $pipe->getSink();
        $sink->write('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'."\n");
        $events = [];
        $duplex = self::buildDuplex(
            onBeforeClose: static function () use ($sink): void {
                $sink->close();
            },
        );
        $duplex->onMessage(static function (array $envelope, ReceiveContext $context) use (&$events): void {
            $events[] = 'message:start';
            delay(0.02);
            $events[] = 'message:end';
        });
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $duplex->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $duplex->start($pipe->getSource(), new WritableBuffer());

        delay(0.001);

        $duplex->close();

        self::assertSame(['message:start', 'message:end', 'drain', 'close'], $events);
    }

    public function testADrainListenerCanStillSendBeforeTheTransportGoesClosed(): void
    {
        $pipe = new Pipe(8_192);
        $sink = $pipe->getSink();
        $outbound = new WritableBuffer();
        $events = [];
        $duplex = self::buildDuplex(
            onBeforeClose: static function () use ($sink, $outbound): void {
                $sink->close();
                $outbound->close();
            },
        );
        $duplex->onDrain(static function () use (&$events, &$duplex): void {
            $events[] = 'drain:start';
            delay(0.01);

            try {
                $duplex->send(new CancelledNotification(params: new CancelledNotificationParams(requestId: new RequestId(id: 1))));
                $events[] = 'drain:sent';
            } catch (\Throwable $e) {
                $events[] = 'drain:refused '.$e::class;
            }
        });
        $duplex->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $duplex->start($pipe->getSource(), $outbound);
        delay(0.01);

        $duplex->close();

        self::assertSame(['drain:start', 'drain:sent', 'close'], $events);
    }

    public function testASendFailureClosingFromItsOwnFiberStillDrainsAndCloses(): void
    {
        $events = [];
        $duplex = self::buildDuplex();
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $duplex->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $duplex->start(new ReadableBuffer(''), self::buildThrowingSink(new \RuntimeException('write failed')));

        try {
            $duplex->send(new CancelledNotification(params: new CancelledNotificationParams(requestId: new RequestId(id: 1))));
            self::fail('Expected the write failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('write failed', $e->getMessage());
        }

        EventLoop::run();

        self::assertSame(['drain', 'close'], $events);
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
        $pipe = new Pipe(8_192);
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

        delay(0.01);

        $duplex->close();

        self::assertSame(['drain', 'close'], $events);
    }

    public function testCloseDrainsRegisteredSideChannelLoopBeforeReturning(): void
    {
        $pipe = new Pipe(8_192);
        $sink = $pipe->getSink();
        $lines = [];
        $duplex = self::buildDuplex();
        $duplex->forwardLines(
            $pipe->getSource(),
            static function (string $line) use (&$lines): void {
                $lines[] = 'start:'.$line;
                delay(0.02);
                $lines[] = 'end:'.$line;
            },
        );
        $sink->write("tail\n");

        delay(0.001);

        $duplex->close();

        self::assertSame(['start:tail', 'end:tail'], $lines);
    }

    public function testCloseFromAFiberAlsoDrainsARegisteredSideChannelLoop(): void
    {
        $pipe = new Pipe(8_192);
        $sink = $pipe->getSink();
        $lines = [];
        $duplex = self::buildDuplex();
        $duplex->forwardLines(
            $pipe->getSource(),
            static function (string $line) use (&$lines): void {
                $lines[] = 'start:'.$line;
                delay(0.02);
                $lines[] = 'end:'.$line;
            },
        );
        $sink->write("tail\n");

        delay(0.001);

        async(static function () use ($duplex): void {
            $duplex->close();
        })->await();

        self::assertSame(['start:tail', 'end:tail'], $lines);
    }

    public function testCloseDrainsAParkedReadLoopWithoutAnOnBeforeClose(): void
    {
        $pipe = new Pipe(8_192);
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

        delay(0.01);

        $duplex->close();

        self::assertSame(['drain', 'close'], $events);
        self::assertSame([], $errors, 'Closing the readable should EOF the read loop cleanly, not error.');
    }

    public function testSideChannelOnLineCallingCloseDoesNotSelfDeadlock(): void
    {
        $pipe = new Pipe(8_192);
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

    public function testAConcurrentCloseBlocksUntilTheFirstHasDrained(): void
    {
        $pipe = new Pipe(8_192);
        $sink = $pipe->getSink();
        $events = [];
        $duplex = self::buildDuplex(
            onBeforeClose: static function () use ($sink): void {
                $sink->close();
            },
        );
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain:start';
            delay(0.02);
            $events[] = 'drain:end';
        });

        $duplex->start($pipe->getSource(), new WritableBuffer());
        delay(0.001);

        $first = async(static function () use ($duplex, &$events): void {
            $duplex->close();
            $events[] = 'first:returned';
        });
        $second = async(static function () use ($duplex, &$events): void {
            delay(0.01);
            $duplex->close();
            $events[] = 'second:returned';

            try {
                $duplex->send(new CancelledNotification(params: new CancelledNotificationParams(requestId: new RequestId(id: 1))));
                $events[] = 'second:sent';
            } catch (TransportAlreadyClosedException) {
                $events[] = 'second:send-refused';
            }
        });
        $first->await();
        $second->await();

        self::assertSame(
            ['drain:start', 'drain:end', 'first:returned', 'second:returned', 'second:send-refused'],
            $events,
            'A concurrent close must block until the first has drained, and a send after it returns must be refused.',
        );
    }

    public function testADrainListenerReenteringCloseReturnsImmediately(): void
    {
        $events = [];
        $duplex = self::buildDuplex();
        $duplex->onDrain(static function () use (&$events, &$duplex): void {
            $events[] = 'drain:start';
            $duplex->close();
            $events[] = 'drain:end';
        });
        $duplex->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $duplex->close();

        self::assertSame(['drain:start', 'drain:end', 'close'], $events);
    }

    public function testAMessageListenerCallingCloseDuringAConcurrentCloseReturnsEarly(): void
    {
        $pipe = new Pipe(8_192);
        $sink = $pipe->getSink();
        $sink->write('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'."\n");
        $events = [];
        $duplex = self::buildDuplex(
            onBeforeClose: static function () use ($sink): void {
                $sink->close();
            },
        );
        $duplex->onMessage(static function (array $envelope, ReceiveContext $context) use (&$events, &$duplex): void {
            $events[] = 'message:start';
            delay(0.02);
            $duplex->close();
            $events[] = 'message:end';
        });
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });

        $duplex->start($pipe->getSource(), new WritableBuffer());
        delay(0.005);

        $duplex->close();
        $events[] = 'returned';

        self::assertSame(
            ['message:start', 'message:end', 'drain', 'returned'],
            $events,
            'A message listener closing during a concurrent close must return early, not deadlock the drain awaiting it.',
        );
    }

    public function testASideChannelOnLineCallingCloseDuringAConcurrentCloseReturnsEarly(): void
    {
        $pipe = new Pipe(8_192);
        $sink = $pipe->getSink();
        $events = [];
        $duplex = self::buildDuplex();
        $duplex->forwardLines(
            $pipe->getSource(),
            static function (string $line) use (&$events, &$duplex): void {
                $events[] = 'line:start';
                delay(0.02);
                $duplex->close();
                $events[] = 'line:end';
            },
        );
        $sink->write("tail\n");
        delay(0.005);

        $duplex->close();
        $events[] = 'returned';

        self::assertSame(
            ['line:start', 'line:end', 'returned'],
            $events,
            'A side-channel listener closing during a concurrent close must return early, not deadlock the drain awaiting it.',
        );
    }

    public function testAConcurrentCloseStillReturnsWhenTheOwnersTeardownThrows(): void
    {
        $events = [];
        $duplex = self::buildDuplex(
            onBeforeClose: static function (): void {
                throw new \RuntimeException('teardown boom');
            },
        );
        $duplex->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
            delay(0.01);
        });

        $owner = async(static function () use ($duplex, &$events): void {
            try {
                $duplex->close();
            } catch (\RuntimeException $e) {
                $events[] = 'owner:'.$e->getMessage();
            }
        });
        $concurrent = async(static function () use ($duplex, &$events): void {
            delay(0.005);
            $duplex->close();
            $events[] = 'concurrent:returned';
        });
        $owner->await();
        $concurrent->await();

        self::assertSame(
            ['drain', 'owner:teardown boom', 'concurrent:returned'],
            $events,
            'A teardown failure must still release a concurrent close, not leave it awaiting forever.',
        );
    }

    public function testAConcurrentCloseFromMainAwaitsAFiberOwnedCloseOfAnIdleDuplex(): void
    {
        /** @var DeferredFuture<null> $gate */
        $gate = new DeferredFuture();
        $events = [];
        $duplex = self::buildDuplex();
        $duplex->onDrain(static function () use ($gate, &$events): void {
            $events[] = 'drain:start';
            $gate->getFuture()->await();
            $events[] = 'drain:end';
        });

        $owner = async(static function () use ($duplex, &$events): void {
            $duplex->close();
            $events[] = 'owner:returned';
        });
        async(static function () use ($gate): void {
            delay(0.01);
            $gate->complete();
        });
        delay(0.005);

        $duplex->close();
        $events[] = 'main:returned';
        $owner->await();

        self::assertSame(['drain:start', 'drain:end', 'owner:returned', 'main:returned'], $events);
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
                self::buildThrowingSource(new \RuntimeException('side-channel boom')),
                static function (): void {},
            );

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
            $tornDown = false;
            $duplex = self::buildDuplex(
                onBeforeClose: static function () use (&$tornDown): void {
                    $tornDown = true;
                },
            );
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
            self::assertTrue($tornDown);
        } finally {
            EventLoop::setErrorHandler($previousHandler);
        }
    }

    public function testCloseStillSettlesWhenOnBeforeCloseThrows(): void
    {
        $closed = false;
        $duplex = self::buildDuplex(
            onBeforeClose: static function (): void {
                throw new \RuntimeException('teardown boom');
            },
        );
        $duplex->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        try {
            $duplex->close();
            self::fail('Expected the teardown failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('teardown boom', $e->getMessage());
        }

        self::assertTrue($closed);

        $this->expectException(TransportAlreadyClosedException::class);

        $duplex->send(new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())));
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

            $duplex->start(self::buildThrowingSource(new \RuntimeException('stdin boom')), new WritableBuffer());
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
        self::assertSame(-32_700, $reported[0]->error->code, 'Malformed JSON must surface a ParseError (-32700).');
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
        self::assertSame(-32_600, $reported[0]->error->code, 'Non-object envelope must surface an InvalidRequestError (-32600).');
    }

    public function testMalformedJsonFiresErrorListenerEvenWithoutOnParseFailure(): void
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

        self::assertCount(1, $errors, 'A malformed line must reach the error listeners, matching the non-object arm.');
        self::assertInstanceOf(\JsonException::class, $errors[0]);
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
            new ReadableIterableStream(new \ArrayIterator(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'."\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        self::assertSame([$boom], $errors);
    }

    public function testSendWritesSerializedEnvelopeFollowedByNewline(): void
    {
        $writable = new WritableBuffer();
        $duplex = self::buildDuplex();
        $message = new DiscoverRequest(id: new RequestId(id: 99), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()));

        $duplex->start(new ReadableBuffer(''), $writable);
        $duplex->send($message);
        EventLoop::run();
        $writable->close();

        self::assertSame(
            json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n",
            $writable->buffer(),
        );
    }

    public function testSendEmitsUnescapedSlashesAndUnicode(): void
    {
        $writable = new WritableBuffer();
        $duplex = self::buildDuplex();

        $duplex->start(new ReadableBuffer(''), $writable);
        $duplex->send(new CancelledNotification(params: new CancelledNotificationParams(
            requestId: new RequestId(id: 1),
            reason: 'café/done',
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
            new DiscoverRequest(id: new RequestId(id: 42), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())),
            '"server/discover" request with id "42"',
        ];

        yield 'notification' => [
            new CancelledNotification(params: new CancelledNotificationParams(requestId: new RequestId(id: 7))),
            '"notifications/cancelled" notification',
        ];

        yield 'response envelope' => [
            new GenericResultResponse(id: new RequestId(id: 99), result: new EmptyResult()),
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

        $duplex->start(new ReadableBuffer(''), self::buildThrowingSink($boom));

        $closesBeforeSend = $closes;

        try {
            $duplex->send(new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())));
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

        $duplex->start(new ReadableBuffer(''), self::buildThrowingSink($boom));

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
            new DiscoverRequest(id: new RequestId(id: 42), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())),
            '"server/discover" request with id "42"',
        ];

        yield 'notification' => [
            new CancelledNotification(params: new CancelledNotificationParams(requestId: new RequestId(id: 7))),
            '"notifications/cancelled" notification',
        ];

        yield 'response envelope' => [
            new GenericResultResponse(id: new RequestId(id: 99), result: new EmptyResult()),
            'a response envelope',
        ];
    }

    public function testSendDuringConcurrentCloseWrapsByteStreamFailureInTransportAlreadyClosedException(): void
    {
        $boom = new \RuntimeException('writable was concurrently closed');
        $logger = new ArrayLogger();
        $writable = self::buildThrowingSink(
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
            $this->duplexUnderConcurrentClose->send(new DiscoverRequest(id: new RequestId(id: 1), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())));
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
        $writable = self::buildThrowingSink(
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
            new DiscoverRequest(id: new RequestId(id: 42), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create())),
            '"server/discover" request with id "42"',
        ];

        yield 'notification' => [
            new CancelledNotification(params: new CancelledNotificationParams(requestId: new RequestId(id: 7))),
            '"notifications/cancelled" notification',
        ];

        yield 'response envelope' => [
            new GenericResultResponse(id: new RequestId(id: 99), result: new EmptyResult()),
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

        $duplex->start(self::buildThrowingSource($boom), new WritableBuffer());
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
            new ReadableIterableStream(new \ArrayIterator(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'."\n"])),
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
            new ReadableIterableStream(new \ArrayIterator(['{"jsonrpc":"2.0","id":1,"method":"tools/list"}'."\n"])),
            new WritableBuffer(),
        );
        EventLoop::run();

        self::assertSame(
            [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']],
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
            self::buildThrowingSource($boom),
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

        $completions = (new \ReflectionProperty(LineDuplex::class, 'sideChannelCompletions'))->getValue($duplex);
        $fibers = (new \ReflectionProperty(LineDuplex::class, 'sideChannelFibers'))->getValue($duplex);

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

    private static function buildThrowingSource(\Throwable $error): ReadableStream
    {
        $source = self::createStub(ReadableStream::class);
        $source->method('read')->willThrowException($error);

        return $source;
    }

    /**
     * @param null|\Closure(): void $beforeThrow Runs inside the write, before it fails
     */
    private static function buildThrowingSink(\Throwable $error, ?\Closure $beforeThrow = null): WritableStream
    {
        $sink = self::createStub(WritableStream::class);
        $sink->method('write')->willReturnCallback(
            static function () use ($error, $beforeThrow): void {
                if (null !== $beforeThrow) {
                    $beforeThrow();
                }

                throw $error;
            },
        );

        return $sink;
    }
}
