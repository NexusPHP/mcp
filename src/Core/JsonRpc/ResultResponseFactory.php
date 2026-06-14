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
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
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

/**
 * Wraps a handler result in the typed `*ResultResponse` for its request method,
 * falling back to `GenericResultResponse` for results with no dedicated envelope.
 *
 * @internal
 */
final class ResultResponseFactory
{
    /**
     * @param JsonRpcRequest<non-empty-string, array<string, mixed>> $request
     * @param Result<array<string, mixed>>                           $result
     *
     * @return JsonRpcResultResponse<array<string, mixed>>
     */
    public static function wrap(JsonRpcRequest $request, Result $result): JsonRpcResultResponse
    {
        $id = $request->id;

        return match (true) {
            $request instanceof CallToolRequest && ($result instanceof CallToolResult || $result instanceof InputRequiredResult) => new CallToolResultResponse(id: $id, result: $result),
            $request instanceof GetPromptRequest && ($result instanceof GetPromptResult || $result instanceof InputRequiredResult) => new GetPromptResultResponse(id: $id, result: $result),
            $request instanceof ReadResourceRequest && ($result instanceof ReadResourceResult || $result instanceof InputRequiredResult) => new ReadResourceResultResponse(id: $id, result: $result),
            $request instanceof CompleteRequest && $result instanceof CompleteResult => new CompleteResultResponse(id: $id, result: $result),
            $request instanceof DiscoverRequest && $result instanceof DiscoverResult => new DiscoverResultResponse(id: $id, result: $result),
            $request instanceof ListPromptsRequest && $result instanceof ListPromptsResult => new ListPromptsResultResponse(id: $id, result: $result),
            $request instanceof ListResourcesRequest && $result instanceof ListResourcesResult => new ListResourcesResultResponse(id: $id, result: $result),
            $request instanceof ListResourceTemplatesRequest && $result instanceof ListResourceTemplatesResult => new ListResourceTemplatesResultResponse(id: $id, result: $result),
            $request instanceof ListToolsRequest && $result instanceof ListToolsResult => new ListToolsResultResponse(id: $id, result: $result),
            default => new GenericResultResponse(id: $id, result: $result),
        };
    }
}
