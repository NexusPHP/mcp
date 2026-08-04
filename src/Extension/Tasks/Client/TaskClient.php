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

namespace Nexus\Mcp\Extension\Tasks\Client;

use Amp\Cancellation;
use Nexus\Mcp\Client\Client;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\RequestParams\CallToolRequestParams;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;
use Nexus\Mcp\Extension\Tasks\Client\Exception\StalledTaskException;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\Request\CancelTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\GetTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\UpdateTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\RequestParams\CancelTaskRequestParams;
use Nexus\Mcp\Extension\Tasks\Schema\RequestParams\GetTaskRequestParams;
use Nexus\Mcp\Extension\Tasks\Schema\RequestParams\UpdateTaskRequestParams;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Extension\Tasks\Schema\Result\GetTaskResult;
use Nexus\Mcp\Extension\Tasks\Schema\ResultResponse\GetTaskResultResponse;
use Nexus\Mcp\Extension\Tasks\Schema\ResultResponse\TaskCallToolResultResponse;

use function Amp\delay;

/**
 * Task-aware client surface over `Client::sendRequest()`, stamping every
 * request with the lifecycle `_meta` the server-side gates check.
 */
final readonly class TaskClient implements TaskClientInterface
{
    /**
     * @var \Closure(float, null|Cancellation): void
     */
    private \Closure $sleep;

    /**
     * @param int                                           $stallCeiling Consecutive `input_required` polls yielding no
     *                                                                    new requests before `awaitTask()` gives up
     * @param null|\Closure(float, null|Cancellation): void $sleep        Suspends between polls, seconds
     */
    public function __construct(
        private Client $client,
        private int $stallCeiling = 60,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds, ?Cancellation $cancellation): void {
            delay($seconds, cancellation: $cancellation);
        };
    }

    #[\Override]
    public function callToolAsTask(string $name, ?array $arguments = null, ?array $inputResponses = null, ?string $requestState = null): CallToolResult|CreateTaskResult|InputRequiredResult
    {
        $response = $this->client->sendRequest(
            new CallToolRequest(
                id: $this->client->mintRequestId(),
                params: new CallToolRequestParams(
                    name: $name,
                    meta: $this->client->stampMeta(),
                    arguments: $arguments,
                    inputResponses: $inputResponses,
                    requestState: $requestState,
                ),
            ),
            TaskCallToolResultResponse::class,
        );

        return $response->result;
    }

    #[\Override]
    public function getTask(string $taskId): GetTaskResult
    {
        $response = $this->client->sendRequest(
            new GetTaskRequest(
                id: $this->client->mintRequestId(),
                params: new GetTaskRequestParams(taskId: $taskId, meta: $this->client->stampMeta()),
            ),
            GetTaskResultResponse::class,
        );

        return $response->result;
    }

    #[\Override]
    public function updateTask(string $taskId, array $inputResponses): void
    {
        $this->client->sendRequest(
            new UpdateTaskRequest(
                id: $this->client->mintRequestId(),
                params: new UpdateTaskRequestParams(taskId: $taskId, inputResponses: $inputResponses, meta: $this->client->stampMeta()),
            ),
            GenericResultResponse::class,
        );
    }

    #[\Override]
    public function cancelTask(string $taskId): void
    {
        $this->client->sendRequest(
            new CancelTaskRequest(
                id: $this->client->mintRequestId(),
                params: new CancelTaskRequestParams(taskId: $taskId, meta: $this->client->stampMeta()),
            ),
            GenericResultResponse::class,
        );
    }

    #[\Override]
    public function awaitTask(CreateTaskResult $task, ?\Closure $resolveInputRequests = null, ?Cancellation $cancellation = null): GetTaskResult
    {
        $intervalMs = $task->pollIntervalMs ?? 1_000;
        $answeredKeys = [];
        $stalledPolls = 0;

        while (true) {
            $state = $this->getTask($task->taskId);
            $intervalMs = $state->pollIntervalMs ?? $intervalMs;

            if (TaskStatus::InputRequired === $state->status) {
                $unanswered = array_diff_key($state->inputRequests ?? [], $answeredKeys);
                $responses = [] === $unanswered || null === $resolveInputRequests
                    ? []
                    : $resolveInputRequests($unanswered);

                if ([] !== $responses) {
                    $this->updateTask($task->taskId, $responses);
                    $answeredKeys += array_fill_keys(array_keys($responses), true);
                    $stalledPolls = 0;
                } elseif (++$stalledPolls >= $this->stallCeiling) {
                    throw new StalledTaskException($task->taskId, $stalledPolls);
                }
            } elseif (TaskStatus::Working !== $state->status) {
                return $state;
            }

            ($this->sleep)($intervalMs / 1_000, $cancellation);
        }
    }
}
