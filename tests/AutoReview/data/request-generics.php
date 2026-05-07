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

use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\Request\ListRootsRequest;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\Request\SetLevelRequest;
use Nexus\Mcp\Core\Schema\Request\SubscribeRequest;
use Nexus\Mcp\Core\Schema\Request\UnsubscribeRequest;

use function PHPStan\Testing\assertType;

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
