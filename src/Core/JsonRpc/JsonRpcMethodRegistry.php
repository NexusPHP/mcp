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
            Request\CompleteRequest::getMethod() => Request\CompleteRequest::class,
            Request\ElicitRequest::getMethod() => Request\ElicitRequest::class,
            Request\InitializeRequest::getMethod() => Request\InitializeRequest::class,
            Request\SetLevelRequest::getMethod() => Request\SetLevelRequest::class,
            Request\PingRequest::getMethod() => Request\PingRequest::class,
            Request\GetPromptRequest::getMethod() => Request\GetPromptRequest::class,
            Request\ListPromptsRequest::getMethod() => Request\ListPromptsRequest::class,
            Request\ListResourcesRequest::getMethod() => Request\ListResourcesRequest::class,
            Request\ReadResourceRequest::getMethod() => Request\ReadResourceRequest::class,
            Request\SubscribeRequest::getMethod() => Request\SubscribeRequest::class,
            Request\ListResourceTemplatesRequest::getMethod() => Request\ListResourceTemplatesRequest::class,
            Request\UnsubscribeRequest::getMethod() => Request\UnsubscribeRequest::class,
            Request\ListRootsRequest::getMethod() => Request\ListRootsRequest::class,
            Request\CreateMessageRequest::getMethod() => Request\CreateMessageRequest::class,
            Request\CancelTaskRequest::getMethod() => Request\CancelTaskRequest::class,
            Request\GetTaskRequest::getMethod() => Request\GetTaskRequest::class,
            Request\ListTasksRequest::getMethod() => Request\ListTasksRequest::class,
            Request\GetTaskPayloadRequest::getMethod() => Request\GetTaskPayloadRequest::class,
            Request\CallToolRequest::getMethod() => Request\CallToolRequest::class,
            Request\ListToolsRequest::getMethod() => Request\ListToolsRequest::class,
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
            Notification\CancelledNotification::getMethod() => Notification\CancelledNotification::class,
            Notification\ElicitationCompleteNotification::getMethod() => Notification\ElicitationCompleteNotification::class,
            Notification\InitializedNotification::getMethod() => Notification\InitializedNotification::class,
            Notification\LoggingMessageNotification::getMethod() => Notification\LoggingMessageNotification::class,
            Notification\ProgressNotification::getMethod() => Notification\ProgressNotification::class,
            Notification\PromptListChangedNotification::getMethod() => Notification\PromptListChangedNotification::class,
            Notification\ResourceListChangedNotification::getMethod() => Notification\ResourceListChangedNotification::class,
            Notification\ResourceUpdatedNotification::getMethod() => Notification\ResourceUpdatedNotification::class,
            Notification\RootsListChangedNotification::getMethod() => Notification\RootsListChangedNotification::class,
            Notification\TaskStatusNotification::getMethod() => Notification\TaskStatusNotification::class,
            Notification\ToolListChangedNotification::getMethod() => Notification\ToolListChangedNotification::class,
        ];
    }
}
