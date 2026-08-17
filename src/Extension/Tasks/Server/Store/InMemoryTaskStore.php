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

use Nexus\Mcp\Core\JsonRpc\ErrorFactory;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Request\InputRequest;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Server\Exception\InputRequestKeyReusedException;

/**
 * In-memory implementation of `TaskStoreInterface`.
 */
final class InMemoryTaskStore implements TaskStoreInterface
{
    /**
     * @var array<non-empty-string, TaskRecord>
     */
    private array $records = [];

    /**
     * @var array<non-empty-string, \DateTimeImmutable>
     */
    private array $terminalAt = [];

    /**
     * @var \Closure(): \DateTimeImmutable
     */
    private readonly \Closure $clock;

    /**
     * @param null|\Closure(): \DateTimeImmutable $clock
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable();
    }

    #[\Override]
    public function createTask(string $toolName, ?array $arguments, ?int $ttlMs, int $pollIntervalMs): TaskRecord
    {
        $instant = ($this->clock)();
        $nowMs = self::toMillisecondTimestamp($instant);

        foreach (array_keys($this->records) as $taskId) {
            $this->resolveTask($taskId, $nowMs);
        }

        $now = $instant->format(\DateTimeInterface::ATOM);
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
        return $this->resolveTask($taskId, null);
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
            return $record;
        }

        return $this->replaceRecord($record, $record->status, [
            'pendingInputRequests' => $pending,
            'inputResponses' => $accepted,
        ]);
    }

    /**
     * `findTask()` with the current time in epoch milliseconds precomputed, or `null` to read the clock on demand.
     *
     * @param non-empty-string $taskId
     */
    private function resolveTask(string $taskId, ?int $nowMs): ?TaskRecord
    {
        $record = $this->records[$taskId] ?? null;

        if (null === $record) {
            return null;
        }

        if ($this->hasExpired($taskId, $record, $nowMs)) {
            unset($this->records[$taskId], $this->terminalAt[$taskId]);

            return null;
        }

        if ($this->hasOverstayed($record, $nowMs)) {
            return $this->replaceRecord($record, TaskStatus::Failed, [
                'error' => ErrorFactory::create(ProtocolErrorCode::InternalError, 'The task did not settle within its ttl.')->toArray(),
                'pendingInputRequests' => [],
                'inputResponses' => [],
                'requestState' => null,
            ]);
        }

        return $record;
    }

    /**
     * @param array{
     *   result?: null|array<string, mixed>,
     *   error?: null|array<string, mixed>,
     *   pendingInputRequests?: array<int|non-empty-string, InputRequest>,
     *   inputResponses?: array<int|non-empty-string, InputResponse>,
     *   requestState?: null|string,
     *   issuedInputKeys?: array<array-key, true>,
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
    private function hasExpired(string $taskId, TaskRecord $record, ?int $nowMs): bool
    {
        if (null === $record->ttlMs) {
            return false;
        }

        $terminalAt = $this->terminalAt[$taskId] ?? null;

        if (null === $terminalAt) {
            return false;
        }

        $nowMs ??= self::toMillisecondTimestamp(($this->clock)());

        return $record->ttlMs <= $nowMs - self::toMillisecondTimestamp($terminalAt);
    }

    /**
     * True when a non-terminal record has outlived `createdAt + ttlMs`, which SEP-2663 allows failing.
     */
    private function hasOverstayed(TaskRecord $record, ?int $nowMs): bool
    {
        if (null === $record->ttlMs || self::isTerminal($record->status)) {
            return false;
        }

        $nowMs ??= self::toMillisecondTimestamp(($this->clock)());

        return $record->ttlMs <= $nowMs - self::toMillisecondTimestamp(new \DateTimeImmutable($record->createdAt));
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
