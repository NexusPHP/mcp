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

namespace Nexus\Mcp\Extension\Tasks\Schema\Result;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ServerResult;
use Nexus\Mcp\Core\Schema\Result\TaskHandleResult;
use Nexus\Mcp\Core\Validation\EnumValueValidator;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;

/**
 * A task handle returned in lieu of a request's standard result, identifying
 * the long-running task the client polls with `tasks/get`.
 *
 * @extends Result<array{
 *   _meta?: array<string, mixed>,
 *   resultType: non-empty-string,
 *   taskId: non-empty-string,
 *   status: non-empty-string,
 *   createdAt: non-empty-string,
 *   lastUpdatedAt: non-empty-string,
 *   ttlMs: null|int,
 *   statusMessage?: non-empty-string,
 *   pollIntervalMs?: int,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2663-tasks-extension.md
 */
final readonly class CreateTaskResult extends Result implements ServerResult, TaskHandleResult
{
    /**
     * @param non-empty-string      $taskId
     * @param non-empty-string      $createdAt
     * @param non-empty-string      $lastUpdatedAt
     * @param null|non-empty-string $statusMessage
     */
    public function __construct(
        public string $taskId,
        public TaskStatus $status,
        public string $createdAt,
        public string $lastUpdatedAt,
        public ?int $ttlMs,
        public ?string $statusMessage = null,
        public ?int $pollIntervalMs = null,
        ResultMetaObject $meta = new GenericResultMetaObject(),
    ) {
        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('taskId', '"result" is missing the required "taskId" key.');
        $taskId = $data['taskId'];
        Assert::that($taskId)->isNonEmptyString('"result.taskId" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('status', '"result" is missing the required "status" key.');
        $status = EnumValueValidator::parse(TaskStatus::class, $data['status'], '"result.status"');

        Assert::that($data)->hasOffset('createdAt', '"result" is missing the required "createdAt" key.');
        $createdAt = $data['createdAt'];
        Assert::that($createdAt)->isNonEmptyString('"result.createdAt" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('lastUpdatedAt', '"result" is missing the required "lastUpdatedAt" key.');
        $lastUpdatedAt = $data['lastUpdatedAt'];
        Assert::that($lastUpdatedAt)->isNonEmptyString('"result.lastUpdatedAt" must be a non-empty string, {type} given.');

        Assert::that($data)->hasOffset('ttlMs', '"result" is missing the required "ttlMs" key.');
        $ttlMs = $data['ttlMs'];
        Assert::that($ttlMs)->nullOr()->isPositiveInt('"result.ttlMs" must be null or a positive integer, {value} given.');

        $statusMessage = null;

        if (\array_key_exists('statusMessage', $data)) {
            $statusMessage = $data['statusMessage'];
            Assert::that($statusMessage)->isNonEmptyString('"result.statusMessage" must be a non-empty string, {type} given.');
        }

        $pollIntervalMs = null;

        if (\array_key_exists('pollIntervalMs', $data)) {
            $pollIntervalMs = $data['pollIntervalMs'];
            Assert::that($pollIntervalMs)->isPositiveInt('"result.pollIntervalMs" must be a positive integer, {value} given.');
        }

        $meta = new GenericResultMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = GenericResultMetaObject::fromArray($data['_meta']);
        }

        return new self(
            taskId: $taskId,
            status: $status,
            createdAt: $createdAt,
            lastUpdatedAt: $lastUpdatedAt,
            ttlMs: $ttlMs,
            statusMessage: $statusMessage,
            pollIntervalMs: $pollIntervalMs,
            meta: $meta,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];
        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        $data['resultType'] = self::getResultType();
        $data['taskId'] = $this->taskId;
        $data['status'] = $this->status->value;
        $data['createdAt'] = $this->createdAt;
        $data['lastUpdatedAt'] = $this->lastUpdatedAt;
        $data['ttlMs'] = $this->ttlMs;

        if (null !== $this->statusMessage) {
            $data['statusMessage'] = $this->statusMessage;
        }

        if (null !== $this->pollIntervalMs) {
            $data['pollIntervalMs'] = $this->pollIntervalMs;
        }

        return $data;
    }

    #[\Override]
    public function rebuildWithMeta(ResultMetaObject $meta): static
    {
        return new self(
            taskId: $this->taskId,
            status: $this->status,
            createdAt: $this->createdAt,
            lastUpdatedAt: $this->lastUpdatedAt,
            ttlMs: $this->ttlMs,
            statusMessage: $this->statusMessage,
            pollIntervalMs: $this->pollIntervalMs,
            meta: $meta,
        );
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Task->value;
    }
}
