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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(PendingCoroutines::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PendingCoroutinesTest extends TestCase
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

        // Suspend A so it is still pending when flushPending snapshots the set.
        // A then tracks B from inside its own body, mid-flush.
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
}
