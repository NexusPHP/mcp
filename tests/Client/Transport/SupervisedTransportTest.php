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
     * A supervisor left open keeps an armed respawn watcher in the process-global event loop, which the
     * next test would run. Closing cancels it.
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
            // Asserted by testStartFailureOnTheFirstConnectionPropagates.
        }

        // A transport that never started is neither running nor closed, so the caller may try again.
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
            // The status lands first, so the close this instance emits is the one that throws. The real
            // transport catches what its exit listeners raise, so mirror that here.
            self::connectionAt($spawned, 0)->emitUnexpectedExit(streamClosesFirst: false);
        } catch (\RuntimeException) {
            // Expected: TransportInterface documents that a throw aborts the listener chain.
        }

        EventLoop::run();

        // The replacement's close would throw the same way, and teardown is not the assertion.
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

        // The fixture fires its close listeners *and* the exit signal, as a dying subprocess does.
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

        // Client maps onDrain to flushPending(), so losing it turns a graceful shutdown into an abrupt one.
        self::assertSame(1, $drains);
    }

    public function testASupersededConnectionIsClosedNotJustForgotten(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);
        $transport->start();

        // Model a peer whose exit status lands while its streams are still held open elsewhere.
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
            // The peer's failure propagates, but not before the caller has been told.
        }

        self::assertSame(1, $closes, 'A peer failing on the way down must not swallow the close signal.');

        // Released regardless, or the next send() would route to a peer that is already gone.
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
            // Expected: the listener chain aborts, but the peer must still come down.
        }

        $subscription->dispose();

        self::assertTrue(self::connectionAt($spawned, 0)->closed, 'A listener must not be able to leak the peer.');
    }

    public function testAFailedStartOwesNoCloseWhenTheTransportIsLaterClosed(): void
    {
        $inner = new SupervisableRecordingTransport();
        $inner->startError = new \RuntimeException('cannot start');

        $transport = $this->built[] = new SupervisedTransport(static fn(): SupervisableTransportInterface => $inner);

        $closes = 0;
        $transport->onClose(static function () use (&$closes): void {
            ++$closes;
        });

        try {
            $transport->start();
        } catch (\Throwable) {
            // Asserted by testStartFailureOnTheFirstConnectionPropagates.
        }

        $transport->close();

        self::assertSame(0, $closes, 'A connection that never started owes the caller no close.');
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
            // Expected: the error chain aborts, but supervision must still shut down.
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
            // An application that treats a lost connection as terminal. The peer's death has already
            // been reported at this point, so supervision must not spawn a replacement behind its back.
            if (++$reentries > 2) {
                self::fail('close() must not drive unbounded re-entry through its own close listeners.');
            }

            $transport->close();
        });

        $transport->start();
        self::connectionAt($spawned, 0)->emitUnexpectedExit(streamClosesFirst: false);
        EventLoop::run();

        self::assertCount(1, $spawned);

        // A closed transport does no respawn bookkeeping at all: no budget spent, no attempt announced.
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

    public function testCloseBeforeStartEmitsNoCloseEvent(): void
    {
        $spawned = [];
        $transport = $this->buildTransport($spawned);

        $closes = 0;
        $transport->onClose(static function () use (&$closes): void {
            ++$closes;
        });

        $transport->close();

        self::assertSame(0, $closes);
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

        // A replacement that answers and dies again is still a crash loop. The protocol layer replays its
        // own state on every reconnect, so a peer serving something proves nothing about its health.
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

        // Past the window, so the peer that dies next opens a budget of its own instead of spending the
        // one the first death opened.
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

        // Exactly one window later. The window has not yet elapsed, so this death spends the same budget
        // rather than opening a new one.
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
        // Starts inside the window, as a monotonic source does in a freshly booted container.
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

        // Seven seconds after the first restart, so well inside its window. Measured from the clock's
        // origin instead, twelve seconds would have elapsed and this would open a fresh budget.
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

        // Real elapsed time, not an injected reading: a default clock that never advances would leave
        // `maxRestarts` a lifetime budget.
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
                // The second peer suspends on the way up, exactly as a real subprocess launch does.
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

        // Lands while the replacement is still starting: it is a live connection owing its own close, not
        // a promised one owing an extra.
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

        // The status lands without the streams tearing down, so the supervisor's own retire is what
        // closes the peer, and that suspends.
        self::connectionAt($spawned, 0)->emitUnexpectedExit(streamClosesFirst: false);
        EventLoop::run();

        // A peer minted after the close would be live, unsupervised, and closed by nothing.
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

        // Registered after the peer's own close, so only the abandonment emission reaches it.
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

        // Three deaths with no message in between: two respawns, then the budget is spent.
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
                    // Budget arithmetic that stopped terminating would otherwise recurse here until the
                    // suite is killed on the clock rather than failing on an assertion.
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

        // The first connection is minted but throws out of start(), so the caller never sees it.
        try {
            $transport->start();
        } catch (\Throwable) {
            // Asserted by testStartFailureOnTheFirstConnectionPropagates.
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

        // Withdrawing the promised replacement is the only signal a caller holding state for it will get.
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

        // A served message clears the budget, so the second death is another first-attempt respawn.
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
            // The whole point of the signal: a listener rebuilding per-connection state must be able to
            // write it to the fresh peer from inside the callback.
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

        // The second attempt failed to start and the third took its place, so exactly one reconnect
        // is owed, not two.
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

        if (! $inner instanceof SupervisableRecordingTransport) {
            self::fail(\sprintf('Expected a spawned connection at index %d, got %d in all.', $index, \count($spawned)));
        }

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
