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

namespace Nexus\Mcp\Core\JsonRpc;

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\Request\SubscriptionsListenRequest;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\Result\SubscriptionsListenResult;
use Nexus\Mcp\Core\Schema\Result\TaskHandleResult;
use Nexus\Mcp\Core\Schema\ResultResponse\CallToolResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\CompleteResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\DiscoverResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\GetPromptResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListPromptsResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListResourcesResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListResourceTemplatesResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListToolsResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ReadResourceResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\SubscriptionsListenResultResponse;

/**
 * Factory for the typed `*ResultResponse` matching a handler result's request method.
 *
 * @internal
 */
final class ResultResponseFactory
{
    /**
     * @param JsonRpcRequest<non-empty-string> $request
     *
     * @throws \InvalidArgumentException
     */
    public static function wrap(JsonRpcRequest $request, Result $result): JsonRpcResultResponse
    {
        $id = $request->id;

        if (
            $request instanceof CallToolRequest
            && ($result instanceof CallToolResult || $result instanceof InputRequiredResult)
        ) {
            return new CallToolResultResponse(id: $id, result: $result);
        }

        if (
            $request instanceof GetPromptRequest
            && ($result instanceof GetPromptResult || $result instanceof InputRequiredResult)
        ) {
            return new GetPromptResultResponse(id: $id, result: $result);
        }

        if (
            $request instanceof ReadResourceRequest
            && ($result instanceof ReadResourceResult || $result instanceof InputRequiredResult)
        ) {
            return new ReadResourceResultResponse(id: $id, result: $result);
        }

        if ($request instanceof CompleteRequest && $result instanceof CompleteResult) {
            return new CompleteResultResponse(id: $id, result: $result);
        }

        if ($request instanceof DiscoverRequest && $result instanceof DiscoverResult) {
            return new DiscoverResultResponse(id: $id, result: $result);
        }

        if ($request instanceof ListPromptsRequest && $result instanceof ListPromptsResult) {
            return new ListPromptsResultResponse(id: $id, result: $result);
        }

        if ($request instanceof ListResourcesRequest && $result instanceof ListResourcesResult) {
            return new ListResourcesResultResponse(id: $id, result: $result);
        }

        if ($request instanceof ListResourceTemplatesRequest && $result instanceof ListResourceTemplatesResult) {
            return new ListResourceTemplatesResultResponse(id: $id, result: $result);
        }

        if ($request instanceof ListToolsRequest && $result instanceof ListToolsResult) {
            return new ListToolsResultResponse(id: $id, result: $result);
        }

        if ($request instanceof SubscriptionsListenRequest && $result instanceof SubscriptionsListenResult) {
            return new SubscriptionsListenResultResponse(id: $id, result: $result);
        }

        if (
            ($request instanceof CallToolRequest && $result instanceof TaskHandleResult)
            || ! \array_key_exists($request::getMethod(), JsonRpcMethodRegistry::requests())
            || $result instanceof EmptyResult
        ) {
            return new GenericResultResponse(id: $id, result: $result);
        }

        throw new \InvalidArgumentException(\sprintf(
            'Handler for "%s" returned %s, which is not a valid result for that method.',
            $request::getMethod(),
            $result::class,
        ));
    }
}
