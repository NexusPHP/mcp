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

namespace Nexus\Mcp\Core\Dispatch;

/**
 * Counts occurrences of a repeatable event and admits only the first and every
 * `interval`th one for logging, so a peer cannot amplify a flood into one log
 * record per message.
 *
 * @internal
 */
final class LogThrottle
{
    /**
     * @var int<0, max>
     */
    private int $count = 0;

    /**
     * @param positive-int $interval
     */
    public function __construct(private readonly int $interval = 100)
    {
    }

    /**
     * Records an occurrence, answering whether this one should be logged.
     */
    public function admits(): bool
    {
        ++$this->count;

        return 1 === $this->count || 0 === $this->count % $this->interval;
    }

    /**
     * How many occurrences have been recorded, logged or suppressed.
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        return $this->count;
    }
}
