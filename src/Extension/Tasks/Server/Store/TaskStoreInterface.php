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

use Nexus\Mcp\Core\Schema\Request\InputRequest;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Extension\Tasks\Server\Exception\InputRequestKeyReusedException;

/**
 * Durable storage for task records. `createTask()` MUST make the record visible
 * to `findTask()` before returning, and the `trySet*` transitions are sticky
 * once terminal.
 */
interface TaskStoreInterface
{
    /**
     * Creates a durable record in the `working` status and returns it.
     *
     * @param non-empty-string             $toolName
     * @param null|array<array-key, mixed> $arguments
     */
    public function createTask(string $toolName, ?array $arguments, ?int $ttlMs, int $pollIntervalMs): TaskRecord;

    /**
     * The record for `$taskId`, or `null` when it never existed or its
     * retention window has lapsed.
     *
     * @param non-empty-string $taskId
     */
    public function findTask(string $taskId): ?TaskRecord;

    /**
     * Returns the task to `working`, clearing its pending input requests.
     *
     * @param non-empty-string $taskId
     *
     * @return bool `false` when the record is terminal or absent
     */
    public function trySetWorking(string $taskId): bool;

    /**
     * Completes the task with its stored result payload, a tool error result included.
     *
     * @param non-empty-string     $taskId
     * @param array<string, mixed> $result
     *
     * @return bool `false` when the record is terminal or absent
     */
    public function trySetCompleted(string $taskId, array $result): bool;

    /**
     * Fails the task with a protocol-level error payload.
     *
     * @param non-empty-string      $taskId
     * @param array<string, mixed>  $error
     * @param null|non-empty-string $statusMessage
     *
     * @return bool `false` when the record is terminal or absent
     */
    public function trySetFailed(string $taskId, array $error, ?string $statusMessage = null): bool;

    /**
     * @param non-empty-string $taskId
     *
     * @return bool `false` when the record is terminal or absent
     */
    public function trySetCancelled(string $taskId): bool;

    /**
     * Parks the task in `input_required` with the given requests and the
     * continuation token to re-dispatch with, refusing a key already issued
     * since request keys are unique per task.
     *
     * @param non-empty-string                          $taskId
     * @param array<int|non-empty-string, InputRequest> $inputRequests
     *
     * @return bool `false` when the record is terminal or absent
     *
     * @throws InputRequestKeyReusedException
     */
    public function trySetInputRequired(string $taskId, array $inputRequests, ?string $requestState): bool;

    /**
     * Merges answers into the record, ignoring a response for a key that is not
     * currently outstanding and accumulating every accepted answer for the next
     * re-dispatch.
     *
     * @param non-empty-string                           $taskId
     * @param array<int|non-empty-string, InputResponse> $inputResponses
     *
     * @return null|TaskRecord the updated record, or `null` when it is absent
     */
    public function resolveInputRequests(string $taskId, array $inputResponses): ?TaskRecord;
}
