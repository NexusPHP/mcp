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

namespace Nexus\Mcp\Extension\Tasks\Server\Handler;

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\GetTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Result\GetTaskResult;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskStoreInterface;
use Nexus\Mcp\Extension\Tasks\Server\TaskOwnership;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `tasks/get` request by delegating to a `TaskStoreInterface`.
 *
 * @implements RequestHandlerInterface<'tasks/get', GetTaskResult, ServerContext>
 */
final readonly class GetTaskRequestHandler implements RequestHandlerInterface
{
    public function __construct(private TaskStoreInterface $store)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): GetTaskResult
    {
        \assert($request instanceof GetTaskRequest);
        \assert($context instanceof ServerContext);

        $record = TaskOwnership::findTask($this->store, $context, $request->params->taskId);

        return new GetTaskResult(
            taskId: $record->taskId,
            status: $record->status,
            createdAt: $record->createdAt,
            lastUpdatedAt: $record->lastUpdatedAt,
            ttlMs: $record->ttlMs,
            result: $record->result,
            error: $record->error,
            inputRequests: $record->pendingInputRequests,
            statusMessage: $record->statusMessage,
            pollIntervalMs: $record->pollIntervalMs,
        );
    }
}
