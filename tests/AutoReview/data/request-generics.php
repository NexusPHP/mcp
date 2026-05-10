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

use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\CancelTaskRequest;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Request\CreateMessageRequest;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\GetTaskPayloadRequest;
use Nexus\Mcp\Core\Schema\Request\GetTaskRequest;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\Request\ListRootsRequest;
use Nexus\Mcp\Core\Schema\Request\ListTasksRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\Request\SetLevelRequest;
use Nexus\Mcp\Core\Schema\Request\SubscribeRequest;
use Nexus\Mcp\Core\Schema\Request\UnsubscribeRequest;

use function PHPStan\Testing\assertType;

assertType('\'completion/complete\'', CompleteRequest::method());
assertType('\'initialize\'', InitializeRequest::method());
assertType('\'logging/setLevel\'', SetLevelRequest::method());
assertType('\'ping\'', PingRequest::method());
assertType('\'prompts/get\'', GetPromptRequest::method());
assertType('\'prompts/list\'', ListPromptsRequest::method());
assertType('\'resources/list\'', ListResourcesRequest::method());
assertType('\'resources/read\'', ReadResourceRequest::method());
assertType('\'resources/subscribe\'', SubscribeRequest::method());
assertType('\'resources/templates/list\'', ListResourceTemplatesRequest::method());
assertType('\'resources/unsubscribe\'', UnsubscribeRequest::method());
assertType('\'roots/list\'', ListRootsRequest::method());
assertType('\'sampling/createMessage\'', CreateMessageRequest::method());
assertType('\'tasks/cancel\'', CancelTaskRequest::method());
assertType('\'tasks/get\'', GetTaskRequest::method());
assertType('\'tasks/list\'', ListTasksRequest::method());
assertType('\'tasks/result\'', GetTaskPayloadRequest::method());
assertType('\'tools/call\'', CallToolRequest::method());
assertType('\'tools/list\'', ListToolsRequest::method());
