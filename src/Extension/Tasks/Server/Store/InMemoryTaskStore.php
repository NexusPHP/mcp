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
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Server\Exception\InputRequestKeyReusedException;

/**
 * Process-local task store keeping every record in an array, with retention
 * expiry applied on lookup and swept opportunistically on task creation.
 */
final class InMemoryTaskStore implements TaskStoreInterface
{
    /**
     * @var array<non-empty-string, TaskRecord>
     */
    private array $records = [];

    /**
     * Wall-clock instants of each record's terminal transition, keyed by task
     * id, from which the retention window is measured.
     *
     * @var array<non-empty-string, \DateTimeImmutable>
     */
    private array $terminalAt = [];

    /**
     * @var \Closure(): \DateTimeImmutable
     */
    private readonly \Closure $clock;

    /**
     * @param null|\Closure(): \DateTimeImmutable $clock Supplies both the record timestamps and the retention arithmetic
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable();
    }

    #[\Override]
    public function createTask(string $toolName, ?array $arguments, ?int $ttlMs, int $pollIntervalMs): TaskRecord
    {
        // Terminal records whose retention lapsed would otherwise survive until
        // something polls their exact id again.
        foreach (array_keys($this->terminalAt) as $taskId) {
            $this->findTask($taskId);
        }

        $now = ($this->clock)()->format(\DateTimeInterface::ATOM);
        $taskId = bin2hex(random_bytes(16));

        $record = new TaskRecord(
            taskId: $taskId,
            toolName: $toolName,
            status: TaskStatus::Working,
            createdAt: $now,
            lastUpdatedAt: $now,
            ttlMs: $ttlMs,
            pollIntervalMs: $pollIntervalMs,
            arguments: $arguments,
        );

        $this->records[$taskId] = $record;

        return $record;
    }

    #[\Override]
    public function findTask(string $taskId): ?TaskRecord
    {
        $record = $this->records[$taskId] ?? null;

        if (null === $record) {
            return null;
        }

        if ($this->hasExpired($taskId, $record)) {
            unset($this->records[$taskId], $this->terminalAt[$taskId]);

            return null;
        }

        return $record;
    }

    #[\Override]
    public function trySetWorking(string $taskId): bool
    {
        $record = $this->findLive($taskId);

        if (null === $record) {
            return false;
        }

        $this->replaceRecord($record, TaskStatus::Working, ['pendingInputRequests' => []]);

        return true;
    }

    #[\Override]
    public function trySetCompleted(string $taskId, array $result): bool
    {
        $record = $this->findLive($taskId);

        if (null === $record) {
            return false;
        }

        $this->replaceRecord($record, TaskStatus::Completed, [
            'result' => $result,
            'pendingInputRequests' => [],
            'inputResponses' => [],
            'requestState' => null,
        ]);

        return true;
    }

    #[\Override]
    public function trySetFailed(string $taskId, array $error, ?string $statusMessage = null): bool
    {
        $record = $this->findLive($taskId);

        if (null === $record) {
            return false;
        }

        $this->replaceRecord($record, TaskStatus::Failed, [
            'error' => $error,
            'statusMessage' => $statusMessage,
            'pendingInputRequests' => [],
            'inputResponses' => [],
            'requestState' => null,
        ]);

        return true;
    }

    #[\Override]
    public function trySetCancelled(string $taskId): bool
    {
        $record = $this->findLive($taskId);

        if (null === $record) {
            return false;
        }

        $this->replaceRecord($record, TaskStatus::Cancelled, [
            'pendingInputRequests' => [],
            'inputResponses' => [],
            'requestState' => null,
        ]);

        return true;
    }

    #[\Override]
    public function trySetInputRequired(string $taskId, array $inputRequests, ?string $requestState): bool
    {
        $record = $this->findLive($taskId);

        if (null === $record) {
            return false;
        }

        foreach (array_keys($inputRequests) as $key) {
            if (isset($record->issuedInputKeys[$key])) {
                throw new InputRequestKeyReusedException($taskId, $key);
            }
        }

        $this->replaceRecord($record, TaskStatus::InputRequired, [
            'pendingInputRequests' => $inputRequests,
            'requestState' => $requestState,
            'issuedInputKeys' => $record->issuedInputKeys + array_fill_keys(array_keys($inputRequests), true),
        ]);

        return true;
    }

    #[\Override]
    public function resolveInputRequests(string $taskId, array $inputResponses): ?TaskRecord
    {
        $record = $this->findTask($taskId);

        if (null === $record) {
            return null;
        }

        $pending = $record->pendingInputRequests;
        $accepted = $record->inputResponses;
        $changed = false;

        foreach ($inputResponses as $key => $response) {
            if (! \array_key_exists($key, $pending)) {
                continue;
            }

            unset($pending[$key]);
            $accepted[$key] = $response;
            $changed = true;
        }

        if (! $changed) {
            // Every supplied key was unknown, already answered, or aimed at a
            // terminal record (whose pending set is empty), so the record
            // acknowledges without change.
            return $record;
        }

        return $this->replaceRecord($record, $record->status, [
            'pendingInputRequests' => $pending,
            'inputResponses' => $accepted,
        ]);
    }

    /**
     * Replaces the record with a copy advanced to `$status`: identity fields
     * carry over, `$changes` names what the transition touches, and both
     * `lastUpdatedAt` and (for a terminal status) the retention anchor are
     * stamped from a single clock read.
     *
     * @param array{
     *   result?: null|array<string, mixed>,
     *   error?: null|array<string, mixed>,
     *   pendingInputRequests?: array<string, InputRequest>,
     *   inputResponses?: array<string, InputResponse>,
     *   requestState?: null|string,
     *   issuedInputKeys?: array<string, true>,
     *   statusMessage?: null|non-empty-string,
     * } $changes
     */
    private function replaceRecord(TaskRecord $record, TaskStatus $status, array $changes): TaskRecord
    {
        $instant = ($this->clock)();

        $updated = new TaskRecord(
            taskId: $record->taskId,
            toolName: $record->toolName,
            status: $status,
            createdAt: $record->createdAt,
            lastUpdatedAt: $instant->format(\DateTimeInterface::ATOM),
            ttlMs: $record->ttlMs,
            pollIntervalMs: $record->pollIntervalMs,
            arguments: $record->arguments,
            result: \array_key_exists('result', $changes) ? $changes['result'] : $record->result,
            error: \array_key_exists('error', $changes) ? $changes['error'] : $record->error,
            pendingInputRequests: $changes['pendingInputRequests'] ?? $record->pendingInputRequests,
            inputResponses: $changes['inputResponses'] ?? $record->inputResponses,
            requestState: \array_key_exists('requestState', $changes) ? $changes['requestState'] : $record->requestState,
            issuedInputKeys: $changes['issuedInputKeys'] ?? $record->issuedInputKeys,
            statusMessage: \array_key_exists('statusMessage', $changes) ? $changes['statusMessage'] : $record->statusMessage,
        );

        $this->records[$record->taskId] = $updated;

        if (self::isTerminal($status)) {
            $this->terminalAt[$record->taskId] = $instant;
        }

        return $updated;
    }

    /**
     * The record for `$taskId` when it exists, is unexpired, and is not terminal.
     *
     * @param non-empty-string $taskId
     */
    private function findLive(string $taskId): ?TaskRecord
    {
        $record = $this->findTask($taskId);

        if (null === $record) {
            return null;
        }

        return self::isTerminal($record->status) ? null : $record;
    }

    /**
     * @param non-empty-string $taskId
     */
    private function hasExpired(string $taskId, TaskRecord $record): bool
    {
        if (null === $record->ttlMs) {
            return false;
        }

        $terminalAt = $this->terminalAt[$taskId] ?? null;

        if (null === $terminalAt) {
            return false;
        }

        $elapsedMs = self::toMillisecondTimestamp(($this->clock)()) - self::toMillisecondTimestamp($terminalAt);

        return $elapsedMs >= $record->ttlMs;
    }

    private static function isTerminal(TaskStatus $status): bool
    {
        return match ($status) {
            TaskStatus::Completed, TaskStatus::Cancelled, TaskStatus::Failed => true,
            TaskStatus::Working, TaskStatus::InputRequired => false,
        };
    }

    private static function toMillisecondTimestamp(\DateTimeImmutable $instant): int
    {
        return $instant->getTimestamp() * 1_000 + intdiv((int) $instant->format('u'), 1_000);
    }
}
