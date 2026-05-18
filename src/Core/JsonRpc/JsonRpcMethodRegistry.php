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
 * Spec-default method → class maps consumed by `JsonRpcMessageParser`.
 */
final class JsonRpcMethodRegistry
{
    /**
     * Keyed by spec method literal (`completion/complete`, `initialize`, etc.), sorted by key.
     *
     * @return array<non-empty-string, class-string<JsonRpcRequest<non-empty-string>>>
     */
    public static function requests(): array
    {
        return [
            Request\CompleteRequest::method() => Request\CompleteRequest::class,
            Request\ElicitRequest::method() => Request\ElicitRequest::class,
            Request\InitializeRequest::method() => Request\InitializeRequest::class,
            Request\SetLevelRequest::method() => Request\SetLevelRequest::class,
            Request\PingRequest::method() => Request\PingRequest::class,
            Request\GetPromptRequest::method() => Request\GetPromptRequest::class,
            Request\ListPromptsRequest::method() => Request\ListPromptsRequest::class,
            Request\ListResourcesRequest::method() => Request\ListResourcesRequest::class,
            Request\ReadResourceRequest::method() => Request\ReadResourceRequest::class,
            Request\SubscribeRequest::method() => Request\SubscribeRequest::class,
            Request\ListResourceTemplatesRequest::method() => Request\ListResourceTemplatesRequest::class,
            Request\UnsubscribeRequest::method() => Request\UnsubscribeRequest::class,
            Request\ListRootsRequest::method() => Request\ListRootsRequest::class,
            Request\CreateMessageRequest::method() => Request\CreateMessageRequest::class,
            Request\CancelTaskRequest::method() => Request\CancelTaskRequest::class,
            Request\GetTaskRequest::method() => Request\GetTaskRequest::class,
            Request\ListTasksRequest::method() => Request\ListTasksRequest::class,
            Request\GetTaskPayloadRequest::method() => Request\GetTaskPayloadRequest::class,
            Request\CallToolRequest::method() => Request\CallToolRequest::class,
            Request\ListToolsRequest::method() => Request\ListToolsRequest::class,
        ];
    }

    /**
     * Keyed by spec method literal (`notifications/cancelled`, etc.), sorted by key.
     *
     * @return array<non-empty-string, class-string<JsonRpcNotification<non-empty-string>>>
     */
    public static function notifications(): array
    {
        return [
            Notification\CancelledNotification::method() => Notification\CancelledNotification::class,
            Notification\ElicitationCompleteNotification::method() => Notification\ElicitationCompleteNotification::class,
            Notification\InitializedNotification::method() => Notification\InitializedNotification::class,
            Notification\LoggingMessageNotification::method() => Notification\LoggingMessageNotification::class,
            Notification\ProgressNotification::method() => Notification\ProgressNotification::class,
            Notification\PromptListChangedNotification::method() => Notification\PromptListChangedNotification::class,
            Notification\ResourceListChangedNotification::method() => Notification\ResourceListChangedNotification::class,
            Notification\ResourceUpdatedNotification::method() => Notification\ResourceUpdatedNotification::class,
            Notification\RootsListChangedNotification::method() => Notification\RootsListChangedNotification::class,
            Notification\TaskStatusNotification::method() => Notification\TaskStatusNotification::class,
            Notification\ToolListChangedNotification::method() => Notification\ToolListChangedNotification::class,
        ];
    }
}
