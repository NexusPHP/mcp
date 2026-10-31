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

namespace Nexus\Mcp\Extension\Tasks\Server\Store;

/**
 * A task store that records the caller that created each task.
 */
interface OwnedTaskStoreInterface extends TaskStoreInterface
{
    /**
     * Creates a record in the `working` status that `findTask()` reports with `$owner`.
     *
     * @param non-empty-string             $toolName
     * @param null|array<array-key, mixed> $arguments
     * @param null|non-empty-string        $owner
     */
    public function createOwnedTask(
        string $toolName,
        ?array $arguments,
        ?int $ttlMs,
        int $pollIntervalMs,
        ?string $owner,
    ): TaskRecord;
}
