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

use Nexus\Mcp\Core\Schema\Annotations;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\InvalidParamsError;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\Error\MethodNotFoundError;
use Nexus\Mcp\Core\Schema\Error\ParseError;
use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;

use function PHPStan\Testing\assertType;

assertType(Icon::class, Icon::fromArray([]));
assertType(Annotations::class, Annotations::fromArray([]));
assertType(MetaObject::class, MetaObject::fromArray([]));
assertType(RequestMetaObject::class, RequestMetaObject::fromArray([]));

assertType(EmptyResult::class, EmptyResult::fromArray([]));

assertType(ParseError::class, ParseError::fromArray([]));
assertType(InvalidRequestError::class, InvalidRequestError::fromArray([]));
assertType(MethodNotFoundError::class, MethodNotFoundError::fromArray([]));
assertType(InvalidParamsError::class, InvalidParamsError::fromArray([]));
assertType(InternalError::class, InternalError::fromArray([]));
assertType(JsonRpcErrorResponse::class, JsonRpcErrorResponse::fromArray([]));

assertType(RequestParams::class, RequestParams::fromArray([]));
assertType(NotificationParams::class, NotificationParams::fromArray([]));

assertType(DiscoverRequest::class, DiscoverRequest::fromArray(['id' => 1]));

assertType(ToolListChangedNotification::class, ToolListChangedNotification::fromArray([]));
