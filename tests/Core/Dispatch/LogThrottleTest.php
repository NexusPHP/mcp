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

use Nexus\Mcp\Core\Dispatch\LogThrottle;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(LogThrottle::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class LogThrottleTest extends AbstractMcpTestCase
{
    public function testAdmitsTheFirstOccurrence(): void
    {
        self::assertTrue((new LogThrottle())->admits());
    }

    public function testSuppressesEveryOccurrenceUpToTheInterval(): void
    {
        $throttle = new LogThrottle(3);
        $throttle->admits();

        self::assertFalse($throttle->admits());
    }

    public function testAdmitsAgainOnTheInterval(): void
    {
        $throttle = new LogThrottle(3);

        self::assertSame([true, false, true, false, false, true], [
            $throttle->admits(),
            $throttle->admits(),
            $throttle->admits(),
            $throttle->admits(),
            $throttle->admits(),
            $throttle->admits(),
        ]);
    }

    public function testCountsSuppressedOccurrencesToo(): void
    {
        $throttle = new LogThrottle(100);

        for ($i = 0; $i < 7; ++$i) {
            $throttle->admits();
        }

        self::assertSame(7, $throttle->count());
    }

    public function testStartsAtZero(): void
    {
        self::assertSame(0, (new LogThrottle())->count());
    }

    public function testDefaultsToAdmittingOneInEveryHundred(): void
    {
        $throttle = new LogThrottle();
        $admitted = [];

        for ($i = 1; $i <= 200; ++$i) {
            if ($throttle->admits()) {
                $admitted[] = $i;
            }
        }

        self::assertSame([1, 100, 200], $admitted);
    }
}
