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

namespace Nexus\Mcp\Core\Schema\Task;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Enum\TaskStatus;
use Nexus\Mcp\Core\Validation\EnumValueValidator;
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
        Assert::that($ttl)->nullOr()->isNaturalInt('Task ttl must be a non-negative integer or null.');
        Assert::that($pollInterval)->nullOr()->isNaturalInt('Task pollInterval must be a non-negative integer or null.');

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
        Assert::that($data)->hasOffset('taskId', 'Task data missing "taskId".');
        $taskId = $data['taskId'];
        Assert::that($taskId)->isString('Task "taskId" must be a string, {type} given.');

        Assert::that($data)->hasOffset('status', 'Task data missing "status".');
        $status = EnumValueValidator::parse(TaskStatus::class, $data['status'], 'Task "status"');

        Assert::that($data)->hasOffset('createdAt', 'Task data missing "createdAt".');
        $createdAt = $data['createdAt'];
        Assert::that($createdAt)->isString('Task "createdAt" must be a string, {type} given.');

        Assert::that($data)->hasOffset('lastUpdatedAt', 'Task data missing "lastUpdatedAt".');
        $lastUpdatedAt = $data['lastUpdatedAt'];
        Assert::that($lastUpdatedAt)->isString('Task "lastUpdatedAt" must be a string, {type} given.');

        Assert::that($data)->hasOffset('ttl', 'Task data missing "ttl".');
        $ttl = $data['ttl'];
        Assert::that($ttl)->nullOr()->isInt('Task "ttl" must be an int or null, {type} given.');

        $statusMessage = $data['statusMessage'] ?? null;
        Assert::that($statusMessage)->nullOr()->isString('Task "statusMessage" must be a string or null, {type} given.');

        $pollInterval = $data['pollInterval'] ?? null;
        Assert::that($pollInterval)->nullOr()->isInt('Task "pollInterval" must be an int or null, {type} given.');

        return new self($taskId, $status, $createdAt, $lastUpdatedAt, $ttl, $statusMessage, $pollInterval);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'taskId' => $this->taskId,
            'status' => $this->status->value,
            'createdAt' => Iso8601DateTimeValidator::format($this->createdAt),
            'lastUpdatedAt' => Iso8601DateTimeValidator::format($this->lastUpdatedAt),
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
