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

namespace Nexus\Mcp\Tests\Core\Dispatch;

use Amp\DeferredFuture;
use Nexus\Mcp\Core\Dispatch\PendingCoroutines;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(PendingCoroutines::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PendingCoroutinesTest extends AbstractMcpTestCase
{
    public function testStartsEmpty(): void
    {
        $coroutines = new PendingCoroutines();

        self::assertCount(0, $coroutines);
    }

    public function testTrackRegistersTheFuture(): void
    {
        $coroutines = new PendingCoroutines();
        $deferred = new DeferredFuture();

        $coroutines->track($deferred->getFuture());

        self::assertCount(1, $coroutines);
    }

    public function testACoroutineHoldingNoSlotIsStillAwaitedOnDrain(): void
    {
        $coroutines = new PendingCoroutines();
        $working = new DeferredFuture();
        $parked = new DeferredFuture();

        $coroutines->track($working->getFuture());
        $coroutines->track($parked->getFuture(), occupiesSlot: false);

        self::assertCount(1, $coroutines, 'Only the working coroutine holds a slot.');

        $working->complete();
        delay(0.0);

        self::assertCount(0, $coroutines, 'No slot is held, yet the parked coroutine is still tracked.');

        $flush = async(static fn(): null => $coroutines->flushPending());
        delay(0.0);

        self::assertFalse($flush->isComplete(), 'The drain waits on a coroutine that holds no slot.');

        $parked->complete();
        $flush->await();

        self::assertTrue($flush->isComplete());
    }

    public function testTrackedFutureRemovesItselfOnSettle(): void
    {
        $coroutines = new PendingCoroutines();
        $deferred = new DeferredFuture();
        $coroutines->track($deferred->getFuture());

        $deferred->complete();
        $coroutines->flushPending();

        self::assertCount(0, $coroutines);
    }

    public function testFlushPendingAwaitsAllTrackedFutures(): void
    {
        $coroutines = new PendingCoroutines();
        $deferredA = new DeferredFuture();
        $deferredB = new DeferredFuture();
        $coroutines->track($deferredA->getFuture());
        $coroutines->track($deferredB->getFuture());
        $deferredA->complete();
        $deferredB->complete();

        $coroutines->flushPending();

        self::assertCount(0, $coroutines);
    }

    public function testFlushPendingAwaitsFuturesTrackedDuringTheFlush(): void
    {
        $coroutines = new PendingCoroutines();
        $trackedDuringFlush = false;

        $futureA = async(static function () use ($coroutines, &$trackedDuringFlush): void {
            delay(0);
            $coroutines->track(async(static function () use (&$trackedDuringFlush): void {
                $trackedDuringFlush = true;
            }));
        });
        $coroutines->track($futureA);

        $coroutines->flushPending();

        self::assertTrue($trackedDuringFlush, 'A coroutine tracked during the flush must still be awaited.');
        self::assertCount(0, $coroutines);
    }

    public function testFailedFuturesAreStillCleanedUp(): void
    {
        $coroutines = new PendingCoroutines();
        $deferred = new DeferredFuture();
        $coroutines->track($deferred->getFuture()->catch(static fn(\Throwable $e) => null));

        $deferred->error(new \RuntimeException('boom'));
        $coroutines->flushPending();

        self::assertCount(0, $coroutines);
    }

    public function testAnEscapedCoroutineExceptionIsReported(): void
    {
        $logger = new ArrayLogger();
        $coroutines = new PendingCoroutines($logger);
        $failure = new \RuntimeException('boom');
        $coroutines->track(async(static fn(): never => throw $failure));

        $coroutines->flushPending();

        $records = $logger->recordsMatching(LogLevel::ERROR, 'A dispatch coroutine ended in an uncaught exception.');
        self::assertCount(1, $records);
        self::assertSame($failure, $records[0]['context']['exception'] ?? null);
        self::assertCount(0, $coroutines);
    }

    public function testEveryEscapedCoroutineExceptionIsReported(): void
    {
        $logger = new ArrayLogger();
        $coroutines = new PendingCoroutines($logger);
        $coroutines->track(async(static fn(): never => throw new \RuntimeException('first')));
        $coroutines->track(async(static fn(): never => throw new \RuntimeException('second')));

        $coroutines->flushPending();

        self::assertCount(
            2,
            $logger->recordsMatching(LogLevel::ERROR, 'A dispatch coroutine ended in an uncaught exception.'),
        );
    }

    public function testASettledCoroutineIsNotReportedAsAFailure(): void
    {
        $logger = new ArrayLogger();
        $coroutines = new PendingCoroutines($logger);
        $coroutines->track(async(static fn(): string => 'done'));

        $coroutines->flushPending();

        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));
    }
}
