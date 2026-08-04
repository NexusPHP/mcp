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

namespace Nexus\Mcp\Extension\Tasks\Server;

use Amp\Cancellation;
use Amp\DeferredCancellation;

/**
 * In-process registry of the cancellation sources bounding running task
 * fibers. Cancellation does not survive the process: the store record does,
 * and a `tasks/cancel` on another node marks the record without stopping
 * work here.
 *
 * @internal
 */
final class TaskCancellationRegistry
{
    /**
     * @var array<non-empty-string, DeferredCancellation>
     */
    private array $sources = [];

    /**
     * Registers a fresh cancellation source for `$taskId` and returns its token.
     *
     * @param non-empty-string $taskId
     */
    public function register(string $taskId): Cancellation
    {
        $source = new DeferredCancellation();
        $this->sources[$taskId] = $source;

        return $source->getCancellation();
    }

    /**
     * Cancels the in-flight run of `$taskId`, if any. The source stays
     * registered until the cancelled fiber settles and releases it.
     *
     * @param non-empty-string $taskId
     */
    public function cancel(string $taskId): void
    {
        ($this->sources[$taskId] ?? null)?->cancel();
    }

    /**
     * Drops the source of `$taskId` without cancelling, once its run settled.
     *
     * @param non-empty-string $taskId
     */
    public function release(string $taskId): void
    {
        unset($this->sources[$taskId]);
    }
}
