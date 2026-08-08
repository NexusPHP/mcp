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
use Nexus\Mcp\Core\JsonRpc\InputRequestDispatcher;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\Request\InputRequest;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ServerResult;
use Nexus\Mcp\Core\Validation\EnumValueValidator;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;

/**
 * The current state of a task, one envelope shape discriminated by `status`:
 * `completed` carries `result`, `failed` carries `error`, `input_required`
 * carries `inputRequests`, and `working`/`cancelled` carry none of the three.
 *
 * @extends Result<array{
 *   _meta?: template-type<ResultMetaObject, MetaObject, 'T'>,
 *   resultType: non-empty-string,
 *   taskId: non-empty-string,
 *   status: non-empty-string,
 *   createdAt: non-empty-string,
 *   lastUpdatedAt: non-empty-string,
 *   ttlMs: null|int,
 *   statusMessage?: non-empty-string,
 *   pollIntervalMs?: int,
 *   result?: array<string, mixed>,
 *   error?: array<string, mixed>,
 *   inputRequests?: array<string, array<string, mixed>>,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2663-tasks-extension.md
 */
final readonly class GetTaskResult extends Result implements ServerResult
{
    /**
     * @var null|array<string, InputRequest>
     */
    public ?array $inputRequests;

    /**
     * @param non-empty-string                 $taskId
     * @param non-empty-string                 $createdAt
     * @param non-empty-string                 $lastUpdatedAt
     * @param null|array<string, mixed>        $result
     * @param null|array<string, mixed>        $error
     * @param null|array<string, InputRequest> $inputRequests
     * @param null|non-empty-string            $statusMessage
     */
    public function __construct(
        public string $taskId,
        public TaskStatus $status,
        public string $createdAt,
        public string $lastUpdatedAt,
        public ?int $ttlMs,
        public ?array $result = null,
        public ?array $error = null,
        ?array $inputRequests = null,
        public ?string $statusMessage = null,
        public ?int $pollIntervalMs = null,
        ResultMetaObject $meta = new GenericResultMetaObject(),
    ) {
        Assert::that($result)->nullOr()->isMap('"result.result" must be a string-keyed object.');
        Assert::that($error)->nullOr()->isMap('"result.error" must be a string-keyed object.');

        $inputRequests = [] === $inputRequests ? null : $inputRequests;

        if (null !== $inputRequests) {
            Assert::that($inputRequests)
                ->isMap('"result.inputRequests" must be a string-keyed object.')
                ->values()
                ->isInstanceOf(InputRequest::class, 'each "result.inputRequests" entry must be an InputRequest, {type} given.')
            ;
        }

        self::assertStatusPayload($status, $result, $error, $inputRequests);

        $this->inputRequests = $inputRequests;

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

        $result = null;

        if (\array_key_exists('result', $data)) {
            Assert::that($data['result'])
                ->isArray('"result.result" must be an object, {type} given.')
                ->isMap('"result.result" must be a string-keyed object.')
            ;
            $result = $data['result'];
        }

        $error = null;

        if (\array_key_exists('error', $data)) {
            Assert::that($data['error'])
                ->isArray('"result.error" must be an object, {type} given.')
                ->isMap('"result.error" must be a string-keyed object.')
            ;
            $error = $data['error'];
        }

        $inputRequests = null;

        if (\array_key_exists('inputRequests', $data)) {
            Assert::that($data['inputRequests'])
                ->isArray('"result.inputRequests" must be an object, {type} given.')
                ->isMap('"result.inputRequests" must be a string-keyed object.')
                ->values()
                ->isArray('each "result.inputRequests" entry must be an object, {type} given.')
                ->isMap('each "result.inputRequests" entry must be a string-keyed object.')
            ;
            $inputRequests = array_map(InputRequestDispatcher::decode(...), $data['inputRequests']);
        }

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
                ->not()->isNonEmptyList('"result._meta" must be a string-keyed object.')
            ;
            $meta = GenericResultMetaObject::fromArray($data['_meta']);
        }

        return new self(
            taskId: $taskId,
            status: $status,
            createdAt: $createdAt,
            lastUpdatedAt: $lastUpdatedAt,
            ttlMs: $ttlMs,
            result: $result,
            error: $error,
            inputRequests: $inputRequests,
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

        if (null !== $this->result) {
            $data['result'] = $this->result;
        }

        if (null !== $this->error) {
            $data['error'] = $this->error;
        }

        if (null !== $this->inputRequests) {
            $data['inputRequests'] = array_map(
                static fn(InputRequest $request): array => $request->toArray(),
                $this->inputRequests,
            );
        }

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
            result: $this->result,
            error: $this->error,
            inputRequests: $this->inputRequests,
            statusMessage: $this->statusMessage,
            pollIntervalMs: $this->pollIntervalMs,
            meta: $meta,
        );
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }

    /**
     * @param null|array<string, mixed>        $result
     * @param null|array<string, mixed>        $error
     * @param null|array<string, InputRequest> $inputRequests
     */
    private static function assertStatusPayload(TaskStatus $status, ?array $result, ?array $error, ?array $inputRequests): void
    {
        match ($status) {
            TaskStatus::Completed => null !== $result && null === $error && null === $inputRequests
                ? null
                : throw new \InvalidArgumentException('a completed "result" must carry "result" and neither "error" nor "inputRequests".'),
            TaskStatus::Failed => null !== $error && null === $result && null === $inputRequests
                ? null
                : throw new \InvalidArgumentException('a failed "result" must carry "error" and neither "result" nor "inputRequests".'),
            TaskStatus::InputRequired => null !== $inputRequests && null === $result && null === $error
                ? null
                : throw new \InvalidArgumentException('an input_required "result" must carry "inputRequests" and neither "result" nor "error".'),
            TaskStatus::Working, TaskStatus::Cancelled => null === $result && null === $error && null === $inputRequests
                ? null
                : throw new \InvalidArgumentException(\sprintf('a %s "result" must carry none of "result", "error", or "inputRequests".', $status->value)),
        };
    }
}
