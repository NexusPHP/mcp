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

use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\RequestId;
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
use Nexus\Mcp\Core\Schema\ServerCapabilities;

use function PHPStan\Testing\assertType;

$id = new RequestId(id: 1);

$callTool = new CallToolResultResponse(id: $id, result: new CallToolResult(content: []));
assertType(CallToolResultResponse::class, $callTool);
assertType(CallToolResult::class.'|'.InputRequiredResult::class, $callTool->result);

$getPrompt = new GetPromptResultResponse(id: $id, result: new GetPromptResult(messages: []));
assertType(GetPromptResultResponse::class, $getPrompt);
assertType(GetPromptResult::class.'|'.InputRequiredResult::class, $getPrompt->result);

$readResource = new ReadResourceResultResponse(id: $id, result: new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private));
assertType(ReadResourceResultResponse::class, $readResource);
assertType(InputRequiredResult::class.'|'.ReadResourceResult::class, $readResource->result);

$complete = new CompleteResultResponse(id: $id, result: new CompleteResult(completion: ['values' => []]));
assertType(CompleteResultResponse::class, $complete);
assertType(CompleteResult::class, $complete->result);

$discover = new DiscoverResultResponse(
    id: $id,
    result: new DiscoverResult(
        supportedVersions: [],
        capabilities: new ServerCapabilities(),
        ttlMs: 0,
        cacheScope: CacheScope::Private,
    ),
);
assertType(DiscoverResultResponse::class, $discover);
assertType(DiscoverResult::class, $discover->result);

$listPrompts = new ListPromptsResultResponse(id: $id, result: new ListPromptsResult(prompts: [], ttlMs: 0, cacheScope: CacheScope::Private));
assertType(ListPromptsResultResponse::class, $listPrompts);
assertType(ListPromptsResult::class, $listPrompts->result);

$listResources = new ListResourcesResultResponse(id: $id, result: new ListResourcesResult(resources: [], ttlMs: 0, cacheScope: CacheScope::Private));
assertType(ListResourcesResultResponse::class, $listResources);
assertType(ListResourcesResult::class, $listResources->result);

$listResourceTemplates = new ListResourceTemplatesResultResponse(id: $id, result: new ListResourceTemplatesResult(resourceTemplates: [], ttlMs: 0, cacheScope: CacheScope::Private));
assertType(ListResourceTemplatesResultResponse::class, $listResourceTemplates);
assertType(ListResourceTemplatesResult::class, $listResourceTemplates->result);

$listTools = new ListToolsResultResponse(id: $id, result: new ListToolsResult(tools: [], ttlMs: 0, cacheScope: CacheScope::Private));
assertType(ListToolsResultResponse::class, $listTools);
assertType(ListToolsResult::class, $listTools->result);

$generic = new GenericResultResponse(id: $id, result: new EmptyResult());
assertType(GenericResultResponse::class, $generic);
assertType(Result::class, $generic->result);
