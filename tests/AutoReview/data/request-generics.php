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

use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ListRootsRequest;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\Request\SetLevelRequest;
use Nexus\Mcp\Core\Schema\Request\SubscribeRequest;
use Nexus\Mcp\Core\Schema\Request\UnsubscribeRequest;

use function PHPStan\Testing\assertType;

assertType('\'ping\'', PingRequest::method());
assertType('\'logging/setLevel\'', SetLevelRequest::method());
assertType('\'resources/subscribe\'', SubscribeRequest::method());
assertType('\'resources/unsubscribe\'', UnsubscribeRequest::method());
assertType('\'roots/list\'', ListRootsRequest::method());
assertType('\'resources/list\'', ListResourcesRequest::method());
