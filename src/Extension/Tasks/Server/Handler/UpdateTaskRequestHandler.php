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

use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\RequestParams\CallToolRequestParams;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\Request\UpdateTaskRequest;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskStoreInterface;
use Nexus\Mcp\Extension\Tasks\Server\ToolTaskRunner;
use Nexus\Mcp\Server\ServerContext;

/**
 * Handles the `tasks/update` request, re-dispatching the tool call once no input request is outstanding.
 *
 * @implements RequestHandlerInterface<'tasks/update', EmptyResult, ServerContext>
 */
final readonly class UpdateTaskRequestHandler implements RequestHandlerInterface
{
    public function __construct(private TaskStoreInterface $store, private ToolTaskRunner $runner)
    {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): EmptyResult
    {
        \assert($request instanceof UpdateTaskRequest);
        \assert($context instanceof ServerContext);

        $record = $this->store->findTask($request->params->taskId);

        if (null === $record) {
            throw new InvalidParamsException($context->requestId, '"params.taskId" does not name a known task.');
        }

        $accepted = [];

        foreach (array_intersect_key($request->params->inputResponses, $record->pendingInputRequests) as $key => $response) {
            if (! $response instanceof InputResponse) {
                throw new InvalidParamsException($context->requestId, \sprintf('"params.inputResponses" entry "%s" is not a valid input response.', $key));
            }

            $accepted[$key] = $response;
        }

        $updated = $this->store->resolveInputRequests($record->taskId, $accepted);

        if (null === $updated || TaskStatus::InputRequired !== $updated->status || [] !== $updated->pendingInputRequests) {
            return new EmptyResult();
        }

        $this->store->trySetWorking($updated->taskId);

        $call = new CallToolRequest(
            id: $request->id,
            params: new CallToolRequestParams(
                name: $updated->toolName,
                meta: $context->meta,
                arguments: $updated->arguments,
            ),
        );

        $this->runner->startTask(
            $updated->taskId,
            $call,
            $context,
            inputResponses: $updated->inputResponses,
            requestState: $updated->requestState,
        );

        return new EmptyResult();
    }
}
