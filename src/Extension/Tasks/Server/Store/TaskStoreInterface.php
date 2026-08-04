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
 * Durable storage for task records.
 *
 * `createTask()` MUST make the record visible to `findTask()` before it
 * returns: a task handle may only reach the client once a poll for it would
 * resolve. The `trySet*` transitions are sticky: a record whose status is
 * terminal (`completed`, `cancelled`, `failed`) refuses further transitions
 * by returning `false`. Retention starts at the terminal transition, so a
 * live task never expires.
 */
interface TaskStoreInterface
{
    /**
     * Creates a durable record in the `working` status and returns it.
     *
     * @param non-empty-string          $toolName
     * @param null|array<string, mixed> $arguments
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
     * Completes the task with its stored result payload. A tool error result
     * still completes: `failed` is reserved for protocol-level failures.
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
     * Marks the task cancelled.
     *
     * @param non-empty-string $taskId
     *
     * @return bool `false` when the record is terminal or absent
     */
    public function trySetCancelled(string $taskId): bool;

    /**
     * Parks the task in `input_required` with the given requests and the
     * continuation token to re-dispatch with once they are answered. A key
     * already issued over the task's lifetime is refused: request keys are
     * unique per task.
     *
     * @param non-empty-string            $taskId
     * @param array<string, InputRequest> $inputRequests
     *
     * @return bool `false` when the record is terminal or absent
     *
     * @throws InputRequestKeyReusedException
     */
    public function trySetInputRequired(string $taskId, array $inputRequests, ?string $requestState): bool;

    /**
     * Merges answers into the record: responses for keys that are not
     * currently outstanding are ignored, answered keys leave the pending set,
     * and every accepted answer accumulates for the next re-dispatch.
     *
     * @param non-empty-string             $taskId
     * @param array<string, InputResponse> $inputResponses
     *
     * @return null|TaskRecord the updated record, or `null` when it is absent
     */
    public function resolveInputRequests(string $taskId, array $inputResponses): ?TaskRecord;
}
