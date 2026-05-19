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
        Assert::that($taskId)->isNonEmptyString('task "taskId" must be a non-empty string.');
        Assert::that($statusMessage)->nullOr()->isNonEmptyString('task "statusMessage" must be a non-empty string or null.');
        Assert::that($ttl)->nullOr()->isNaturalInt('task "ttl" must be a non-negative integer or null.');
        Assert::that($pollInterval)->nullOr()->isNaturalInt('task "pollInterval" must be a non-negative integer or null.');

        $this->taskId = $taskId;
        $this->createdAt = Iso8601DateTimeValidator::parse($createdAt, 'task "createdAt"');
        $this->lastUpdatedAt = Iso8601DateTimeValidator::parse($lastUpdatedAt, 'task "lastUpdatedAt"');
        $this->statusMessage = $statusMessage;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('taskId', 'task missing the required "taskId" key.');
        $taskId = $data['taskId'];
        Assert::that($taskId)->isString('task "taskId" must be a string, {type} given.');

        Assert::that($data)->hasOffset('status', 'task missing the required "status" key.');
        $status = EnumValueValidator::parse(TaskStatus::class, $data['status'], 'task "status"');

        Assert::that($data)->hasOffset('createdAt', 'task missing the required "createdAt" key.');
        $createdAt = $data['createdAt'];
        Assert::that($createdAt)->isString('task "createdAt" must be a string, {type} given.');

        Assert::that($data)->hasOffset('lastUpdatedAt', 'task missing the required "lastUpdatedAt" key.');
        $lastUpdatedAt = $data['lastUpdatedAt'];
        Assert::that($lastUpdatedAt)->isString('task "lastUpdatedAt" must be a string, {type} given.');

        Assert::that($data)->hasOffset('ttl', 'task missing the required "ttl" key.');
        $ttl = $data['ttl'];
        Assert::that($ttl)->nullOr()->isInt('task "ttl" must be an int or null, {type} given.');

        $statusMessage = $data['statusMessage'] ?? null;
        Assert::that($statusMessage)->nullOr()->isString('task "statusMessage" must be a string or null, {type} given.');

        $pollInterval = $data['pollInterval'] ?? null;
        Assert::that($pollInterval)->nullOr()->isInt('task "pollInterval" must be an int or null, {type} given.');

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
