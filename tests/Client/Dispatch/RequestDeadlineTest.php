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

namespace Nexus\Mcp\Tests\Client\Dispatch;

use Amp\DeferredFuture;
use Nexus\Mcp\Client\Dispatch\RequestDeadline;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(RequestDeadline::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class RequestDeadlineTest extends AbstractMcpTestCase
{
    public function testFiresOnceTheIdleTimeoutElapses(): void
    {
        $deadline = new RequestDeadline(0.05);

        self::assertFalse($deadline->getCancellation()->isRequested());
        self::assertSame(0.0, $deadline->readElapsed());

        delay(0.08);

        self::assertTrue($deadline->getCancellation()->isRequested());
        self::assertSame(0.05, $deadline->readElapsed());
    }

    public function testExtendRestartsTheIdleTimeout(): void
    {
        $deadline = new RequestDeadline(0.08);

        delay(0.05);
        $deadline->extend();
        delay(0.05);

        // 0.10s since creation, so an unextended deadline would already have fired.
        self::assertFalse($deadline->getCancellation()->isRequested());

        delay(0.05);

        self::assertTrue($deadline->getCancellation()->isRequested());
    }

    public function testTheCeilingFiresHoweverOftenTheIdleTimeoutIsExtended(): void
    {
        $deadline = new RequestDeadline(0.06, 0.12);

        for ($tick = 0; $tick < 4; ++$tick) {
            delay(0.04);
            $deadline->extend();
        }

        self::assertTrue($deadline->getCancellation()->isRequested());
        self::assertSame(0.12, $deadline->readElapsed(), 'The ceiling is what elapsed, not the idle window.');

        // The last extend armed an idle timer that outlives the ceiling. Firing second, it must not
        // restate what elapsed.
        delay(0.08);

        self::assertSame(0.12, $deadline->readElapsed());
    }

    public function testACeilingNearerThanTheIdleTimeoutCannotPreemptIt(): void
    {
        // A ceiling nearer than the idle timeout would pre-empt the very deadline it is meant to bound.
        // This is the shape a per-request override longer than the client-wide ceiling produces.
        $deadline = new RequestDeadline(0.15, 0.05);

        delay(0.09);

        self::assertFalse($deadline->getCancellation()->isRequested(), 'The ceiling must not pre-empt the idle timeout.');

        delay(0.09);

        self::assertTrue($deadline->getCancellation()->isRequested());
        self::assertSame(0.15, $deadline->readElapsed());
    }

    public function testAnArmedDeadlineIsNotWorkTheEventLoopWaitsOn(): void
    {
        $deadline = new RequestDeadline(0.05, 0.05);

        try {
            (new DeferredFuture())->getFuture()->await();
            self::fail('Expected the loop to run dry rather than treat the deadline as work.');
        } catch (\Error $e) {
            self::assertStringStartsWith('Event loop terminated without resuming the current suspension', $e->getMessage());
        }

        // Neither timer was ever waited on, so the loop ran dry well before their window could elapse.
        self::assertFalse($deadline->getCancellation()->isRequested());
        self::assertSame(0.0, $deadline->readElapsed());
    }

    public function testReleaseDisarmsBothTimers(): void
    {
        $deadline = new RequestDeadline(0.05, 0.05);

        $deadline->release();
        delay(0.08);

        self::assertFalse($deadline->getCancellation()->isRequested());
        self::assertSame(0.0, $deadline->readElapsed());
    }

    public function testAnUnboundedDeadlineNeverReachesACeiling(): void
    {
        $deadline = new RequestDeadline(0.08, null);

        delay(0.05);
        $deadline->extend();
        delay(0.05);
        $deadline->extend();
        delay(0.05);

        self::assertFalse($deadline->getCancellation()->isRequested());
    }
}
