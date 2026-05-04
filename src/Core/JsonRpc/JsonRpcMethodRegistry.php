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

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Request;

/**
 * Spec-default method → class maps consumed by {@see JsonRpcMessageParser}.
 *
 * The parser merges caller-supplied maps over these defaults with caller-wins
 * precedence. Two uses of that merge share the same path: overriding a spec
 * method (key matches an entry here) or extending the dispatch table with a
 * vendor method (key does not). The registry itself owns no merge logic; see
 * {@see JsonRpcMessageParser::__construct()} for the override/extension contract.
 */
final class JsonRpcMethodRegistry
{
    /**
     * @return array<non-empty-string, class-string<JsonRpcRequest<non-empty-string>>>
     */
    public static function requests(): array
    {
        return [
            Request\InitializeRequest::method() => Request\InitializeRequest::class,
            Request\ListResourcesRequest::method() => Request\ListResourcesRequest::class,
            Request\ListRootsRequest::method() => Request\ListRootsRequest::class,
            Request\PingRequest::method() => Request\PingRequest::class,
            Request\SetLevelRequest::method() => Request\SetLevelRequest::class,
            Request\SubscribeRequest::method() => Request\SubscribeRequest::class,
            Request\UnsubscribeRequest::method() => Request\UnsubscribeRequest::class,
        ];
    }

    /**
     * @return array<non-empty-string, class-string<JsonRpcNotification<non-empty-string>>>
     */
    public static function notifications(): array
    {
        return [
            Notification\CancelledNotification::method() => Notification\CancelledNotification::class,
            Notification\InitializedNotification::method() => Notification\InitializedNotification::class,
            Notification\LoggingMessageNotification::method() => Notification\LoggingMessageNotification::class,
            Notification\ProgressNotification::method() => Notification\ProgressNotification::class,
            Notification\PromptListChangedNotification::method() => Notification\PromptListChangedNotification::class,
            Notification\ResourceListChangedNotification::method() => Notification\ResourceListChangedNotification::class,
            Notification\ResourceUpdatedNotification::method() => Notification\ResourceUpdatedNotification::class,
            Notification\RootsListChangedNotification::method() => Notification\RootsListChangedNotification::class,
            Notification\ToolListChangedNotification::method() => Notification\ToolListChangedNotification::class,
        ];
    }
}
