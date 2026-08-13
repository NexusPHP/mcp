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

namespace Nexus\Mcp\Tests\Client\Transport;

use Nexus\Mcp\Client\Transport\SupervisedTransport;
use Nexus\Mcp\Core\Exception\SupervisionExhaustedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Transport\SupervisableTransportInterface;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Transport\SupervisableRecordingTransport;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(SupervisedTransport::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class SupervisedTransportTest extends AbstractMcpTestCase
{
    /**
     * @var list<SupervisedTransport>
     */
    private array $built = [];

    /**
     * A supervisor left open keeps an armed respawn watcher in the process-global event loop that the next test would run.
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->built as $transport) {
            $transport->close();
        }

        $this->built = [];
    }

    public function testNonPositiveMaxRestartsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('maxRestarts must be a positive integer, 0 given.');

        new SupervisedTransport(static fn(): SupervisableTransportInterface => new SupervisableRecordingTransport(), maxRestarts: 0);
    }

    public function testNegativeRestartDelayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('restartDelay must not be negative, -1.0 given.');

        new SupervisedTransport(static fn(): SupervisableTransportInterface => new SupervisableRecordingTransport(), restartDelay: -1.0);
    }

    public function testAFailedFirstStartLeavesTheTransportRetryable(): void
    {
        $spawned = [];
        $attempts = 0;

        $transport = $this->built[] = new SupervisedTransport(
            static function () use (&$spawned, &$attempts): SupervisableTransportInterface {
                $inner = new SupervisableRecordingTransport();

                if (1 === ++$attempts) {
                    $inner->startError = new \RuntimeException('cannot start');
                }

                $spawned[] = $inner;

                return $inner;
            },
            restartDelay: 0.0,
        );

        $closes = 0;
        $transport->onClose(static function () use (&$closes): void {
            ++$closes;
        });

        try {
            $transport->start();
        } catch (\Throwable) {
        }

        $transport->start();

        self::assertTrue(self::connectionAt($spawned, 1)->started);
        self::assertSame(0, $closes, 'A launch that never succeeded owes the caller no close.');
    }

    public function testSupervisionSurvivesAThrowingCloseListener(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $subscription = $transport->onClose(static function (): void {
            throw new \RuntimeException('listener blew up');
        });

        $transport->start();

        try {
            self::connectionAt($spawned, 0)->emitUnexpectedExit(streamClosesFirst: false);
        } catch (\RuntimeException) {
        }

        EventLoop::run();

        $subscription->dispose();

        self::assertCount(2, $spawned, 'A throwing listener must not silently disable supervision.');
    }

    public function testStartSpawnsAndStartsTheFirstConnection(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $transport->start();

        self::assertCount(1, $spawned);
        self::assertTrue(self::connectionAt($spawned, 0)->started);
    }

    public function testStartTwiceThrows(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);
        $transport->start();

        $this->expectException(TransportAlreadyStartedException::class);
        $this->expectExceptionMessageIs(SupervisedTransport::class.' has already been started.');

        $transport->start();
    }

    public function testStartAfterCloseThrows(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);
        $transport->start();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);
        $this->expectExceptionMessageIs('Cannot start on a closed transport.');

        $transport->start();
    }

    public function testSendBeforeStartThrows(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $this->expectException(TransportNotStartedException::class);
        $this->expectExceptionMessageIs('Cannot send before start() has been called.');

        $transport->send(self::notification());
    }

    public function testSendAfterCloseThrows(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);
        $transport->start();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);
        $this->expectExceptionMessageIs('Cannot send on a closed transport.');

        $transport->send(self::notification());
    }

    public function testSendRoutesToTheCurrentConnection(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);
        $transport->start();

        $transport->send(self::notification());

        self::assertCount(1, self::connectionAt($spawned, 0)->sent);
    }

    public function testSendBetweenDeathAndRespawnIsRefused(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);
        $transport->start();

        self::connectionAt($spawned, 0)->emitUnexpectedExit();

        $this->expectException(TransportAlreadyClosedException::class);
        $this->expectExceptionMessageIs('Cannot send on a closed transport.');

        $transport->send(self::notification());
    }

    public function testUnexpectedExitRespawnsAndSendRoutesToTheReplacement(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);
        $transport->start();

        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertCount(2, $spawned);
        self::assertTrue(self::connectionAt($spawned, 1)->started);

        $transport->send(self::notification());

        self::assertSame([], self::connectionAt($spawned, 0)->sent);
        self::assertCount(1, self::connectionAt($spawned, 1)->sent);
    }

    public function testListenersRegisterOnceAndSurviveRespawn(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $envelopes = [];
        $transport->onMessage(static function (array $envelope) use (&$envelopes): void {
            $envelopes[] = $envelope;
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitMessage(['first' => true]);

        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::connectionAt($spawned, 1)->emitMessage(['second' => true]);

        self::assertSame([['first' => true], ['second' => true]], $envelopes);
    }

    public function testErrorAndDrainForwardFromTheCurrentConnection(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $errors = [];
        $drains = 0;
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });
        $transport->onDrain(static function () use (&$drains): void {
            ++$drains;
        });

        $transport->start();

        $failure = new \RuntimeException('boom');
        self::connectionAt($spawned, 0)->emitError($failure);
        self::connectionAt($spawned, 0)->emitDrain();

        self::assertSame([$failure], $errors);
        self::assertSame(1, $drains);
    }

    public function testEachConnectionEndsWithExactlyOneCloseEmission(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $closes = 0;
        $transport->onClose(static function () use (&$closes): void {
            ++$closes;
        });

        $transport->start();

        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertSame(1, $closes, 'A peer death raises two signals but ends one connection.');
    }

    public function testClosingAfterARespawnEndsTheReplacementToo(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $closes = 0;
        $transport->onClose(static function () use (&$closes): void {
            ++$closes;
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();
        $transport->close();

        self::assertSame(2, $closes, 'One close per connection: the peer that died and the one that replaced it.');
    }

    public function testCloseClosesTheCurrentConnectionAndForwardsItsDrain(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $drains = 0;
        $transport->onDrain(static function () use (&$drains): void {
            ++$drains;
        });

        $transport->start();
        $transport->close();

        self::assertSame(1, $drains);
    }

    public function testASupersededConnectionIsClosedNotJustForgotten(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);
        $transport->start();

        self::connectionAt($spawned, 0)->emitUnexpectedExit(streamClosesFirst: false);
        EventLoop::run();

        self::assertTrue(
            self::connectionAt($spawned, 0)->closed,
            'A replaced connection must be closed, or its read loop stays parked and leaks.',
        );
    }

    public function testAPeerThatThrowsOnCloseStillReportsTheCloseAndIsReleased(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $closes = 0;
        $transport->onClose(static function () use (&$closes): void {
            ++$closes;
        });

        $transport->start();
        self::connectionAt($spawned, 0)->closeError = new \RuntimeException('drain failed');

        try {
            $transport->close();
        } catch (\RuntimeException) {
        }

        self::assertSame(1, $closes, 'A peer failing on the way down must not swallow the close signal.');

        $this->expectException(TransportAlreadyClosedException::class);
        $transport->send(self::notification());
    }

    public function testAThrowingCloseListenerStillReleasesThePeer(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $subscription = $transport->onClose(static function (): void {
            throw new \RuntimeException('listener blew up');
        });

        $transport->start();

        try {
            $transport->close();
        } catch (\RuntimeException) {
        }

        $subscription->dispose();

        self::assertTrue(self::connectionAt($spawned, 0)->closed, 'A listener must not be able to leak the peer.');
    }

    public function testAFailedStartStillGetsDrainAndCloseWhenLaterClosed(): void
    {
        $inner = new SupervisableRecordingTransport();
        $inner->startError = new \RuntimeException('cannot start');

        $transport = $this->built[] = new SupervisedTransport(static fn(): SupervisableTransportInterface => $inner);

        $events = [];
        $transport->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $transport->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        try {
            $transport->start();
        } catch (\Throwable) {
        }

        $transport->close();

        self::assertSame(['drain', 'close'], $events, 'A caller blocking on close must be released even when the start failed.');
    }

    public function testAThrowingErrorListenerStillClosesTheExhaustedTransport(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned, maxRestarts: 1);

        $transport->onError(static function (\Throwable $e): void {
            if ($e instanceof SupervisionExhaustedException) {
                throw new \RuntimeException('listener blew up');
            }
        });

        $transport->start();

        try {
            for ($i = 0; $i < 2; ++$i) {
                self::connectionAt($spawned, $i)->emitUnexpectedExit();
                EventLoop::run();
            }
        } catch (\RuntimeException) {
        }

        $this->expectException(TransportAlreadyClosedException::class);
        $transport->send(self::notification());
    }

    public function testExplicitCloseStopsSupervision(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);
        $transport->start();

        $transport->close();
        EventLoop::run();

        self::assertTrue(self::connectionAt($spawned, 0)->closed);
        self::assertCount(1, $spawned, 'An intentional close must not respawn the peer.');
    }

    public function testClosingFromACloseListenerCancelsTheRespawn(): void
    {
        $spawned = [];
        $logger = new ArrayLogger();
        $transport = $this->buildTransport($spawned, logger: $logger);

        $reentries = 0;
        $transport->onClose(static function () use (&$transport, &$reentries): void {
            if (++$reentries > 2) {
                self::fail('close() must not drive unbounded re-entry through its own close listeners.');
            }

            $transport->close();
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit(streamClosesFirst: false);
        EventLoop::run();

        self::assertCount(1, $spawned);

        self::assertSame([], $logger->recordsMatching(
            LogLevel::WARNING,
            '{label} transport respawning the peer after an unexpected exit (code {exitCode}), attempt {attempt} of {budget}.',
        ));
    }

    public function testCloseIsIdempotent(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $closes = 0;
        $transport->onClose(static function () use (&$closes): void {
            ++$closes;
        });

        $transport->start();
        $transport->close();
        $transport->close();

        self::assertSame(1, $closes);
    }

    public function testCloseAfterAFailedRespawnCatchesAThrowingCloseListener(): void
    {
        $spawned = [];
        $transport = $this->built[] = new SupervisedTransport(
            static function () use (&$spawned): SupervisableTransportInterface {
                $inner = new SupervisableRecordingTransport();

                if ([] !== $spawned) {
                    $inner->startError = new \RuntimeException('respawn fails');
                }

                $spawned[] = $inner;

                return $inner;
            },
            restartDelay: 0.01,
        );

        $errors = [];
        $closes = 0;
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });
        $transport->onClose(static function () use (&$closes): void {
            if (++$closes > 1) {
                throw new \RuntimeException('close listener boom');
            }
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        delay(0.015);

        $transport->close();

        self::assertSame(2, $closes);
        self::assertNotSame([], $errors);
        self::assertSame('close listener boom', end($errors)->getMessage());
    }

    public function testExplicitCloseFiresDrainBeforeClose(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);
        $transport->start();

        $events = [];
        $transport->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $transport->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $transport->close();

        self::assertSame(['drain', 'close'], $events, 'The drain must release in-flight work before close cancels the awaiting callers.');
    }

    public function testCloseBeforeStartFiresDrainThenCloseWithoutSpawning(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $events = [];
        $transport->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $transport->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });

        $transport->close();

        self::assertSame(['drain', 'close'], $events);
        self::assertSame([], $spawned);
    }

    public function testCloseDuringTheRespawnDelayCancelsIt(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned, restartDelay: 0.05);
        $transport->start();

        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        $transport->close();
        EventLoop::run();

        self::assertCount(1, $spawned, 'A close during the backoff window must cancel the pending respawn.');
    }

    public function testASupersededConnectionCannotEmitThroughTheDecorator(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $envelopes = [];
        $closes = 0;
        $transport->onMessage(static function (array $envelope) use (&$envelopes): void {
            $envelopes[] = $envelope;
        });
        $transport->onClose(static function () use (&$closes): void {
            ++$closes;
        });

        $transport->start();
        $dead = self::connectionAt($spawned, 0);
        $dead->emitUnexpectedExit();
        EventLoop::run();

        $dead->emitMessage(['late' => true]);
        $dead->emitClose();

        self::assertSame([], $envelopes, 'A dead connection must not deliver messages after its replacement is live.');
        self::assertSame(1, $closes, 'A dead connection must not re-close the live one.');
    }

    public function testAServedMessageDoesNotClearTheRestartBudget(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned, maxRestarts: 1);

        $errors = [];
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $transport->start();

        for ($i = 0; $i < 2; ++$i) {
            self::connectionAt($spawned, $i)->emitMessage(['served' => $i]);
            self::connectionAt($spawned, $i)->emitUnexpectedExit();
            EventLoop::run();
        }

        self::assertCount(2, $spawned);
        self::assertCount(1, $errors);
        self::assertInstanceOf(SupervisionExhaustedException::class, $errors[0]);
    }

    public function testTheRestartCountStartsAgainInAFreshWindow(): void
    {
        $spawned = [];
        $logger = new ArrayLogger();
        $now = 0.0;
        $transport = $this->buildTransport(
            $spawned,
            maxRestarts: 1,
            logger: $logger,
            restartWindow: 10.0,
            clock: static function () use (&$now): float {
                return $now;
            },
        );

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        $now = 10.5;

        self::connectionAt($spawned, 1)->emitUnexpectedExit();
        EventLoop::run();

        self::assertCount(3, $spawned, 'A death outside the window is a first attempt again.');

        $attempts = array_map(
            static fn(array $record): mixed => $record['context']['attempt'] ?? null,
            $logger->recordsMatching(
                LogLevel::WARNING,
                '{label} transport respawning the peer after an unexpected exit (code {exitCode}), attempt {attempt} of {budget}.',
            ),
        );

        self::assertSame([1, 1], $attempts);
    }

    public function testADeathExactlyOnTheWindowEdgeStaysInsideIt(): void
    {
        $spawned = [];
        $now = 0.0;
        $transport = $this->buildTransport(
            $spawned,
            maxRestarts: 1,
            restartWindow: 10.0,
            clock: static function () use (&$now): float {
                return $now;
            },
        );

        $errors = [];
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        $now = 10.0;

        self::connectionAt($spawned, 1)->emitUnexpectedExit();
        EventLoop::run();

        self::assertCount(2, $spawned);
        self::assertCount(1, $errors);
        self::assertInstanceOf(SupervisionExhaustedException::class, $errors[0]);
    }

    public function testTheWindowOpensAtTheFirstRestartNotAtTheClocksOrigin(): void
    {
        $spawned = [];
        $now = 5.0;
        $transport = $this->buildTransport(
            $spawned,
            maxRestarts: 1,
            restartWindow: 10.0,
            clock: static function () use (&$now): float {
                return $now;
            },
        );

        $errors = [];
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        $now = 12.0;

        self::connectionAt($spawned, 1)->emitUnexpectedExit();
        EventLoop::run();

        self::assertCount(2, $spawned);
        self::assertCount(1, $errors);
        self::assertInstanceOf(SupervisionExhaustedException::class, $errors[0]);
    }

    public function testTheDefaultClockMeasuresTheWindow(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned, maxRestarts: 1, restartWindow: 0.01);

        $errors = [];
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        delay(0.05);

        self::connectionAt($spawned, 1)->emitUnexpectedExit();
        EventLoop::run();

        self::assertCount(3, $spawned);
        self::assertSame([], $errors, 'A death past the window opens a fresh budget rather than spending the old one.');
    }

    public function testClosingWhileAReplacementIsComingUpEmitsOnlyItsOwnClose(): void
    {
        $spawned = [];
        $transport = $this->built[] = new SupervisedTransport(
            static function () use (&$spawned): SupervisableTransportInterface {
                $inner = new SupervisableRecordingTransport();
                $inner->startDelay = [] === $spawned ? 0.0 : 0.05;
                $spawned[] = $inner;

                return $inner;
            },
            restartDelay: 0.0,
        );

        $closes = [];
        $transport->onClose(static function () use (&$closes): void {
            $closes[] = 'close';
        });

        $reconnects = [];
        $transport->onReconnect(static function () use (&$reconnects): void {
            $reconnects[] = 'reconnect';
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();

        async(static function () use ($transport): void {
            delay(0.01);
            $transport->close();
        });
        EventLoop::run();

        self::assertSame(['close', 'close'], $closes, 'One close for the dead peer, one for the replacement.');
        self::assertSame([], $reconnects, 'A replacement the close overtook was never serving.');
        self::assertTrue(self::connectionAt($spawned, 1)->closed);
    }

    public function testAClosePartWayThroughRetiringDoesNotArmAReplacement(): void
    {
        $spawned = [];
        $transport = $this->built[] = new SupervisedTransport(
            static function () use (&$spawned): SupervisableTransportInterface {
                $inner = new SupervisableRecordingTransport();
                $inner->closeDelay = 0.05;
                $spawned[] = $inner;

                return $inner;
            },
            restartDelay: 0.0,
        );

        $transport->start();

        async(static function () use ($transport): void {
            delay(0.01);
            $transport->close();
        });

        self::connectionAt($spawned, 0)->emitUnexpectedExit(streamClosesFirst: false);
        EventLoop::run();

        self::assertCount(1, $spawned);
    }

    public function testAThrowingListenerOnTheAbandonmentCloseIsReported(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned, restartDelay: 0.5);

        $errors = [];
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e->getMessage();
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();

        $reached = [];
        $transport->onClose(static function (): void {
            throw new \RuntimeException('listener blew up');
        });
        $transport->onClose(static function () use (&$reached): void {
            $reached[] = 'reached';
        });

        $transport->close();

        self::assertSame(['listener blew up'], $errors);
        self::assertSame([], $reached, 'The chain still aborts, as TransportInterface documents.');
    }

    public function testANonPositiveRestartWindowThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('restartWindow must be positive, 0.0 given.');

        new SupervisedTransport(
            static fn(): SupervisableTransportInterface => new SupervisableRecordingTransport(),
            restartWindow: 0.0,
        );
    }

    public function testExhaustedBudgetRaisesAndCloses(): void
    {
        $spawned = [];
        $logger = new ArrayLogger();
        $transport = $this->buildTransport($spawned, maxRestarts: 2, logger: $logger);

        $errors = [];
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $transport->start();

        for ($i = 0; $i < 3; ++$i) {
            self::connectionAt($spawned, $i)->emitUnexpectedExit();
            EventLoop::run();
        }

        self::assertCount(3, $spawned);
        self::assertCount(1, $errors);
        self::assertInstanceOf(SupervisionExhaustedException::class, $errors[0]);
        self::assertSame(2, $errors[0]->restarts);
        self::assertSame(
            'Gave up supervising the peer after 2 restart attempt(s) in one window.',
            $errors[0]->getMessage(),
        );

        $this->expectException(TransportAlreadyClosedException::class);
        $transport->send(self::notification());
    }

    public function testExhaustedBudgetIsLogged(): void
    {
        $spawned = [];
        $logger = new ArrayLogger();
        $transport = $this->buildTransport($spawned, maxRestarts: 1, logger: $logger);
        $transport->start();

        for ($i = 0; $i < 2; ++$i) {
            self::connectionAt($spawned, $i)->emitUnexpectedExit();
            EventLoop::run();
        }

        $matches = $logger->recordsMatching(LogLevel::ERROR, '{label} transport exhausted its restart budget of {budget}.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'Supervised client', 'budget' => 1], $matches[0]['context']);
    }

    public function testRespawnIsLogged(): void
    {
        $spawned = [];
        $logger = new ArrayLogger();
        $transport = $this->buildTransport($spawned, logger: $logger);
        $transport->start();

        self::connectionAt($spawned, 0)->emitUnexpectedExit(exitCode: 9);
        EventLoop::run();

        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            '{label} transport respawning the peer after an unexpected exit (code {exitCode}), attempt {attempt} of {budget}.',
        );
        self::assertCount(1, $matches);
        self::assertSame(
            ['label' => 'Supervised client', 'exitCode' => 9, 'attempt' => 1, 'budget' => 3],
            $matches[0]['context'],
        );
    }

    public function testUnknownExitCodeIsLoggedAsUnknown(): void
    {
        $spawned = [];
        $logger = new ArrayLogger();
        $transport = $this->buildTransport($spawned, logger: $logger);
        $transport->start();

        self::connectionAt($spawned, 0)->emitUnexpectedExit(exitCode: null);
        EventLoop::run();

        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            '{label} transport respawning the peer after an unexpected exit (code {exitCode}), attempt {attempt} of {budget}.',
        );
        self::assertCount(1, $matches);
        self::assertSame('unknown', $matches[0]['context']['exitCode'] ?? null);
    }

    public function testAFactoryThatCannotMintAReplacementSpendsBudget(): void
    {
        $spawned = [];
        $attempts = 0;
        $failure = new \RuntimeException('cannot spawn');

        $transport = new SupervisedTransport(
            static function () use (&$spawned, &$attempts, $failure): SupervisableTransportInterface {
                ++$attempts;

                if ($attempts > 8) {
                    // Budget arithmetic that stopped terminating would otherwise recurse until the suite is killed on the clock.
                    self::fail('A spent budget must stop the respawn recursion.');
                }

                if ($attempts > 1) {
                    throw $failure;
                }

                $inner = new SupervisableRecordingTransport();
                $spawned[] = $inner;

                return $inner;
            },
            maxRestarts: 2,
            restartDelay: 0.0,
        );

        $errors = [];
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertSame(3, $attempts, 'Each failed mint spends one unit of budget.');
        self::assertSame([$failure, $failure], \array_slice($errors, 0, 2));
        self::assertSame(
            [SupervisionExhaustedException::class],
            array_map(get_debug_type(...), \array_slice($errors, 2)),
        );
    }

    public function testDefaultRestartBudgetIsThree(): void
    {
        $spawned = [];
        $logger = new ArrayLogger();
        $transport = $this->built[] = new SupervisedTransport(
            static function () use (&$spawned): SupervisableTransportInterface {
                $inner = new SupervisableRecordingTransport();
                $spawned[] = $inner;

                return $inner;
            },
            restartDelay: 0.0,
            logger: $logger,
        );

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            '{label} transport respawning the peer after an unexpected exit (code {exitCode}), attempt {attempt} of {budget}.',
        );
        self::assertSame(3, $matches[0]['context']['budget'] ?? null);
    }

    public function testAConnectionAbandonedDuringStartIsReleased(): void
    {
        $spawned = [];
        $attempts = 0;

        $transport = $this->built[] = new SupervisedTransport(
            static function () use (&$spawned, &$attempts): SupervisableTransportInterface {
                $inner = new SupervisableRecordingTransport();

                if (1 === ++$attempts) {
                    $inner->startError = new \RuntimeException('cannot start');
                }

                $spawned[] = $inner;

                return $inner;
            },
            maxRestarts: 3,
            restartDelay: 0.0,
        );

        $envelopes = [];
        $transport->onMessage(static function (array $envelope) use (&$envelopes): void {
            $envelopes[] = $envelope;
        });

        try {
            $transport->start();
        } catch (\Throwable) {
        }

        self::connectionAt($spawned, 0)->emitMessage(['orphaned' => true]);

        self::assertSame([], $envelopes, 'A connection abandoned during start() must not still reach the caller.');
    }

    public function testStartFailureOnTheFirstConnectionPropagates(): void
    {
        $inner = new SupervisableRecordingTransport();
        $inner->startError = new \RuntimeException('cannot start');

        $transport = $this->built[] = new SupervisedTransport(static fn(): SupervisableTransportInterface => $inner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs('cannot start');

        $transport->start();
    }

    public function testIsReconnectingIsTrueOnlyWhileAReplacementIsPending(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned, restartDelay: 0.05);

        self::assertFalse($transport->isReconnecting(), 'Nothing has been started, so nothing is coming back.');

        $transport->start();
        self::assertFalse($transport->isReconnecting(), 'A live connection is not a pending one.');

        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        self::assertTrue($transport->isReconnecting(), 'The replacement is armed and has not fired yet.');

        EventLoop::run();
        self::assertFalse($transport->isReconnecting(), 'The replacement is serving, so nothing is pending.');
    }

    public function testIsReconnectingIsFalseOnceTheBudgetIsSpent(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned, maxRestarts: 1);
        $transport->onError(static function (): void {});
        $transport->start();

        for ($i = 0; $i < 2; ++$i) {
            self::connectionAt($spawned, $i)->emitUnexpectedExit();
            EventLoop::run();
        }

        self::assertFalse($transport->isReconnecting(), 'No further peer is coming once supervision gave up.');
    }

    public function testClosingCancelsThePendingReplacement(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned, restartDelay: 0.05);
        $transport->start();

        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        $transport->close();

        self::assertFalse($transport->isReconnecting());
    }

    public function testAbandoningAPendingReplacementEmitsASecondClose(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned, restartDelay: 0.05);

        $closes = [];
        $transport->onClose(static function () use (&$closes): void {
            $closes[] = 'close';
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        $afterExit = $closes;

        $transport->close();

        self::assertSame(['close'], $afterExit, 'The dead connection has ended, and a replacement is still promised.');
        self::assertSame(['close', 'close'], $closes);
    }

    public function testClosingWithNoReplacementPendingEmitsOneClose(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $closes = [];
        $transport->onClose(static function () use (&$closes): void {
            $closes[] = 'close';
        });

        $transport->start();
        $transport->close();

        self::assertSame(['close'], $closes, 'A live connection owes exactly its own close.');
    }

    public function testAThrowingReconnectListenerIsReportedAndTheChainContinues(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $errors = [];
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e->getMessage();
        });

        $reached = [];
        $transport->onReconnect(static function (): void {
            throw new \RuntimeException('listener blew up');
        });
        $transport->onReconnect(static function () use (&$reached): void {
            $reached[] = true;
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertSame(['listener blew up'], $errors);
        self::assertSame([true], $reached, 'One listener failing must not cost the rest theirs.');
    }

    public function testTheFirstConnectionIsNotAnnouncedAsAReconnect(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $reconnects = [];
        $transport->onReconnect(static function () use (&$reconnects): void {
            $reconnects[] = true;
        });

        $transport->start();

        self::assertSame([], $reconnects, 'start() already reports the first connection.');
    }

    public function testEachReplacementAnnouncesExactlyOneReconnect(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $reconnects = [];
        $transport->onReconnect(static function () use (&$reconnects): void {
            $reconnects[] = true;
        });

        $transport->start();

        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::connectionAt($spawned, 1)->emitMessage(['served' => true]);
        self::connectionAt($spawned, 1)->emitUnexpectedExit();
        EventLoop::run();

        self::assertSame([true, true], $reconnects);
    }

    public function testReconnectRunsAfterTheCloseForTheConnectionItReplaces(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $order = [];
        $transport->onClose(static function () use (&$order): void {
            $order[] = 'close';
        });
        $transport->onReconnect(static function () use (&$order): void {
            $order[] = 'reconnect';
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertSame(['close', 'reconnect'], $order);
    }

    public function testReconnectSeesTheReplacementAlreadyServing(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $transport->onReconnect(static function () use ($transport): void {
            $transport->send(self::notification());
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertCount(1, self::connectionAt($spawned, 1)->sent);
    }

    public function testAFailedRespawnDoesNotAnnounceAReconnect(): void
    {
        $spawned = [];
        $attempts = 0;
        $transport = $this->built[] = new SupervisedTransport(
            static function () use (&$spawned, &$attempts): SupervisableTransportInterface {
                $inner = new SupervisableRecordingTransport();

                if (2 === ++$attempts) {
                    $inner->startError = new \RuntimeException('cannot start');
                }

                $spawned[] = $inner;

                return $inner;
            },
            restartDelay: 0.0,
        );

        $reconnects = [];
        $transport->onReconnect(static function () use (&$reconnects): void {
            $reconnects[] = true;
        });
        $transport->onError(static function (): void {});

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertCount(3, $spawned);
        self::assertSame([true], $reconnects);
    }

    public function testADisposedReconnectListenerStopsHearingAboutReplacements(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $reconnects = [];
        $subscription = $transport->onReconnect(static function () use (&$reconnects): void {
            $reconnects[] = true;
        });
        $subscription->dispose();

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertCount(2, $spawned);
        self::assertSame([], $reconnects);
    }

    /**
     * @param list<SupervisableRecordingTransport> $spawned
     */
    private static function connectionAt(array $spawned, int $index): SupervisableRecordingTransport
    {
        $inner = $spawned[$index] ?? null;

        self::assertInstanceOf(
            SupervisableRecordingTransport::class,
            $inner,
            \sprintf('Expected a spawned connection at index %d, got %d in all.', $index, \count($spawned)),
        );

        return $inner;
    }

    /**
     * @param list<SupervisableRecordingTransport> $spawned
     * @param null|\Closure(): float               $clock
     */
    private function buildTransport(
        array &$spawned,
        int $maxRestarts = 3,
        float $restartDelay = 0.0,
        ?ArrayLogger $logger = null,
        float $restartWindow = SupervisedTransport::DEFAULT_RESTART_WINDOW,
        ?\Closure $clock = null,
    ): SupervisedTransport {
        $factory = static function () use (&$spawned): SupervisableTransportInterface {
            $inner = new SupervisableRecordingTransport();
            $spawned[] = $inner;

            return $inner;
        };

        $transport = null === $logger
            ? new SupervisedTransport($factory, $maxRestarts, $restartDelay, restartWindow: $restartWindow, clock: $clock)
            : new SupervisedTransport($factory, $maxRestarts, $restartDelay, $logger, $restartWindow, $clock);

        $this->built[] = $transport;

        return $transport;
    }

    private static function notification(): ToolListChangedNotification
    {
        return new ToolListChangedNotification();
    }
}
