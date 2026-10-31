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
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Extension\Tasks\Schema\Request\CancelTaskRequest;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskStoreInterface;
use Nexus\Mcp\Extension\Tasks\Server\TaskCancellationRegistry;
use Nexus\Mcp\Extension\Tasks\Server\TaskOwnership;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `tasks/cancel` request, idempotently on a terminal task.
 *
 * @implements RequestHandlerInterface<'tasks/cancel', EmptyResult, ServerContext>
 */
final readonly class CancelTaskRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private TaskStoreInterface $store,
        private TaskCancellationRegistry $cancellations,
    ) {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): EmptyResult
    {
        \assert($request instanceof CancelTaskRequest);
        \assert($context instanceof ServerContext);

        $taskId = TaskOwnership::findTask($this->store, $context, $request->params->taskId)->taskId;

        $this->store->trySetCancelled($taskId);
        $this->cancellations->cancel($taskId);

        return new EmptyResult();
    }
}
