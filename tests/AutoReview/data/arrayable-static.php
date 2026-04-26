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
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\Request;
use Nexus\Mcp\Core\Schema\RequestMeta;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\Result;

use function PHPStan\Testing\assertType;

assertType(Icon::class, Icon::fromArray([]));
assertType(Annotations::class, Annotations::fromArray([]));
assertType(Meta::class, Meta::fromArray([]));
assertType(RequestMeta::class, RequestMeta::fromArray([]));

assertType(Result\EmptyResult::class, Result\EmptyResult::fromArray([]));

assertType(ParseError::class, ParseError::fromArray([]));
assertType(InvalidRequestError::class, InvalidRequestError::fromArray([]));
assertType(MethodNotFoundError::class, MethodNotFoundError::fromArray([]));
assertType(InvalidParamsError::class, InvalidParamsError::fromArray([]));
assertType(InternalError::class, InternalError::fromArray([]));
assertType(JsonRpcErrorResponse::class, JsonRpcErrorResponse::fromArray([]));

assertType(RequestParams::class, RequestParams::fromArray([]));
assertType(NotificationParams::class, NotificationParams::fromArray([]));

assertType(Request\PingRequest::class, Request\PingRequest::fromArray(['id' => 1]));

assertType(Notification\InitializedNotification::class, Notification\InitializedNotification::fromArray([]));
