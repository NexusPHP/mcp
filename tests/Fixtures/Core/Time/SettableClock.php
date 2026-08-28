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

namespace Nexus\Mcp\Tests\Fixtures\Core\Time;

use Nexus\Clock\Clock;

/**
 * Clock double the test moves to any instant, counting reads.
 */
final class SettableClock implements Clock
{
    public int $reads = 0;

    public function __construct(private \DateTimeImmutable $now)
    {
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        ++$this->reads;

        return $this->now;
    }

    public function travelTo(\DateTimeImmutable|string $instant): void
    {
        $this->now = \is_string($instant) ? new \DateTimeImmutable($instant) : $instant;
    }

    /**
     * Moves the instant by a relative `DateTimeImmutable::modify()` expression.
     */
    public function travel(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
