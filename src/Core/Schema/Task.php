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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Enum\TaskStatus;
use Nexus\Mcp\Core\Validation\Iso8601DateTimeValidator;

/**
 * Data associated with a task.
 *
 * @implements Arrayable<array{
 *   taskId: non-empty-string,
 *   status: value-of<TaskStatus>,
 *   createdAt: non-empty-string,
 *   lastUpdatedAt: non-empty-string,
 *   ttl: null|int,
 *   statusMessage?: non-empty-string,
 *   pollInterval?: int,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#task
 */
final readonly class Task implements Arrayable
{
    /**
     * @var non-empty-string
     */
    public string $taskId;

    public \DateTimeImmutable $createdAt;
    public \DateTimeImmutable $lastUpdatedAt;

    /**
     * @var null|non-empty-string
     */
    public ?string $statusMessage;

    public function __construct(
        string $taskId,
        public TaskStatus $status,
        string $createdAt,
        string $lastUpdatedAt,
        public ?int $ttl,
        ?string $statusMessage = null,
        public ?int $pollInterval = null,
    ) {
        Assert::that($taskId)->isNonEmptyString('Task taskId must be a non-empty string.');
        Assert::that($statusMessage)->nullOr()->isNonEmptyString('Task statusMessage must be a non-empty string or null.');

        if (null !== $ttl && $ttl < 0) {
            throw new \InvalidArgumentException('Task ttl must be a non-negative integer or null.');
        }

        if (null !== $pollInterval && $pollInterval < 0) {
            throw new \InvalidArgumentException('Task pollInterval must be a non-negative integer or null.');
        }

        $this->taskId = $taskId;
        $this->createdAt = Iso8601DateTimeValidator::parse($createdAt, 'Task createdAt');
        $this->lastUpdatedAt = Iso8601DateTimeValidator::parse($lastUpdatedAt, 'Task lastUpdatedAt');
        $this->statusMessage = $statusMessage;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('taskId', 'Task wire data missing "taskId".');
        $taskId = $data['taskId'];
        Assert::that($taskId)->isString('Task wire "taskId" must be a string, {type} given.');

        Assert::that($data)->hasOffset('status', 'Task wire data missing "status".');
        $status = $data['status'];
        Assert::that($status)->isString('Task wire "status" must be a string, {type} given.');

        Assert::that($data)->hasOffset('createdAt', 'Task wire data missing "createdAt".');
        $createdAt = $data['createdAt'];
        Assert::that($createdAt)->isString('Task wire "createdAt" must be a string, {type} given.');

        Assert::that($data)->hasOffset('lastUpdatedAt', 'Task wire data missing "lastUpdatedAt".');
        $lastUpdatedAt = $data['lastUpdatedAt'];
        Assert::that($lastUpdatedAt)->isString('Task wire "lastUpdatedAt" must be a string, {type} given.');

        Assert::that($data)->hasOffset('ttl', 'Task wire data missing "ttl".');
        $ttl = $data['ttl'];
        Assert::that($ttl)->nullOr()->isInt('Task wire "ttl" must be an int or null, {type} given.');

        $statusMessage = $data['statusMessage'] ?? null;
        Assert::that($statusMessage)->nullOr()->isString('Task wire "statusMessage" must be a string or null, {type} given.');

        $pollInterval = $data['pollInterval'] ?? null;
        Assert::that($pollInterval)->nullOr()->isInt('Task wire "pollInterval" must be an int or null, {type} given.');

        return new self($taskId, TaskStatus::from($status), $createdAt, $lastUpdatedAt, $ttl, $statusMessage, $pollInterval);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'taskId' => $this->taskId,
            'status' => $this->status->value,
            'createdAt' => $this->createdAt->format(\DATE_RFC3339),
            'lastUpdatedAt' => $this->lastUpdatedAt->format(\DATE_RFC3339),
            'ttl' => $this->ttl,
        ];

        if (null !== $this->statusMessage) {
            $data['statusMessage'] = $this->statusMessage;
        }

        if (null !== $this->pollInterval) {
            $data['pollInterval'] = $this->pollInterval;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
