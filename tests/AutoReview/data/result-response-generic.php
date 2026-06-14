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
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
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

// The three MRTR envelopes narrow `$result` to their result union with `InputRequiredResult`.
$callTool = new CallToolResultResponse(id: $id, result: new CallToolResult(content: []));
assertType('Nexus\Mcp\Core\Schema\ResultResponse\CallToolResultResponse', $callTool);
assertType('Nexus\Mcp\Core\Schema\Result\CallToolResult|Nexus\Mcp\Core\Schema\Result\InputRequiredResult', $callTool->result);

$getPrompt = new GetPromptResultResponse(id: $id, result: new GetPromptResult(messages: []));
assertType('Nexus\Mcp\Core\Schema\ResultResponse\GetPromptResultResponse', $getPrompt);
assertType('Nexus\Mcp\Core\Schema\Result\GetPromptResult|Nexus\Mcp\Core\Schema\Result\InputRequiredResult', $getPrompt->result);

$readResource = new ReadResourceResultResponse(id: $id, result: new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private));
assertType('Nexus\Mcp\Core\Schema\ResultResponse\ReadResourceResultResponse', $readResource);
assertType('Nexus\Mcp\Core\Schema\Result\InputRequiredResult|Nexus\Mcp\Core\Schema\Result\ReadResourceResult', $readResource->result);

// The six non-MRTR envelopes narrow `$result` to their single result type.
$complete = new CompleteResultResponse(id: $id, result: new CompleteResult(completion: ['values' => []]));
assertType('Nexus\Mcp\Core\Schema\ResultResponse\CompleteResultResponse', $complete);
assertType('Nexus\Mcp\Core\Schema\Result\CompleteResult', $complete->result);

$discover = new DiscoverResultResponse(id: $id, result: new DiscoverResult(
    supportedVersions: [],
    capabilities: new ServerCapabilities(),
    serverInfo: new Implementation(name: 'srv', version: '1.0.0'),
    ttlMs: 0,
    cacheScope: CacheScope::Private,
));
assertType('Nexus\Mcp\Core\Schema\ResultResponse\DiscoverResultResponse', $discover);
assertType('Nexus\Mcp\Core\Schema\Result\DiscoverResult', $discover->result);

$listPrompts = new ListPromptsResultResponse(id: $id, result: new ListPromptsResult(prompts: [], ttlMs: 0, cacheScope: CacheScope::Private));
assertType('Nexus\Mcp\Core\Schema\ResultResponse\ListPromptsResultResponse', $listPrompts);
assertType('Nexus\Mcp\Core\Schema\Result\ListPromptsResult', $listPrompts->result);

$listResources = new ListResourcesResultResponse(id: $id, result: new ListResourcesResult(resources: [], ttlMs: 0, cacheScope: CacheScope::Private));
assertType('Nexus\Mcp\Core\Schema\ResultResponse\ListResourcesResultResponse', $listResources);
assertType('Nexus\Mcp\Core\Schema\Result\ListResourcesResult', $listResources->result);

$listResourceTemplates = new ListResourceTemplatesResultResponse(id: $id, result: new ListResourceTemplatesResult(resourceTemplates: [], ttlMs: 0, cacheScope: CacheScope::Private));
assertType('Nexus\Mcp\Core\Schema\ResultResponse\ListResourceTemplatesResultResponse', $listResourceTemplates);
assertType('Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult', $listResourceTemplates->result);

$listTools = new ListToolsResultResponse(id: $id, result: new ListToolsResult(tools: [], ttlMs: 0, cacheScope: CacheScope::Private));
assertType('Nexus\Mcp\Core\Schema\ResultResponse\ListToolsResultResponse', $listTools);
assertType('Nexus\Mcp\Core\Schema\Result\ListToolsResult', $listTools->result);

// The generic writer keeps the wide base `Result` type (no per-method narrowing).
$generic = new GenericResultResponse(id: $id, result: new EmptyResult());
assertType('Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse', $generic);
assertType('Nexus\Mcp\Core\Schema\Result<array<string, mixed>>', $generic->result);
