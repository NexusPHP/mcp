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
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\Request\ElicitRequest;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\Request\SubscriptionsListenRequest;

use function PHPStan\Testing\assertType;

assertType('\'completion/complete\'', CompleteRequest::getMethod());
assertType('\'elicitation/create\'', ElicitRequest::getMethod());
assertType('\'prompts/get\'', GetPromptRequest::getMethod());
assertType('\'prompts/list\'', ListPromptsRequest::getMethod());
assertType('\'resources/list\'', ListResourcesRequest::getMethod());
assertType('\'resources/read\'', ReadResourceRequest::getMethod());
assertType('\'resources/templates/list\'', ListResourceTemplatesRequest::getMethod());
assertType('\'server/discover\'', DiscoverRequest::getMethod());
assertType('\'subscriptions/listen\'', SubscriptionsListenRequest::getMethod());
assertType('\'tools/call\'', CallToolRequest::getMethod());
assertType('\'tools/list\'', ListToolsRequest::getMethod());
