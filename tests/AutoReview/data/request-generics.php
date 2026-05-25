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
use Nexus\Mcp\Core\Schema\Request\ElicitRequest;
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

assertType('\'completion/complete\'', CompleteRequest::getMethod());
assertType('\'elicitation/create\'', ElicitRequest::getMethod());
assertType('\'initialize\'', InitializeRequest::getMethod());
assertType('\'logging/setLevel\'', SetLevelRequest::getMethod());
assertType('\'ping\'', PingRequest::getMethod());
assertType('\'prompts/get\'', GetPromptRequest::getMethod());
assertType('\'prompts/list\'', ListPromptsRequest::getMethod());
assertType('\'resources/list\'', ListResourcesRequest::getMethod());
assertType('\'resources/read\'', ReadResourceRequest::getMethod());
assertType('\'resources/subscribe\'', SubscribeRequest::getMethod());
assertType('\'resources/templates/list\'', ListResourceTemplatesRequest::getMethod());
assertType('\'resources/unsubscribe\'', UnsubscribeRequest::getMethod());
assertType('\'roots/list\'', ListRootsRequest::getMethod());
assertType('\'sampling/createMessage\'', CreateMessageRequest::getMethod());
assertType('\'tasks/cancel\'', CancelTaskRequest::getMethod());
assertType('\'tasks/get\'', GetTaskRequest::getMethod());
assertType('\'tasks/list\'', ListTasksRequest::getMethod());
assertType('\'tasks/result\'', GetTaskPayloadRequest::getMethod());
assertType('\'tools/call\'', CallToolRequest::getMethod());
assertType('\'tools/list\'', ListToolsRequest::getMethod());
