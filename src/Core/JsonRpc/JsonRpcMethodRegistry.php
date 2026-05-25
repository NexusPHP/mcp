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
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\Notification\ElicitationCompleteNotification;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\Notification\PromptListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceUpdatedNotification;
use Nexus\Mcp\Core\Schema\Notification\RootsListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\TaskStatusNotification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
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
            CompleteRequest::getMethod() => CompleteRequest::class,
            ElicitRequest::getMethod() => ElicitRequest::class,
            InitializeRequest::getMethod() => InitializeRequest::class,
            SetLevelRequest::getMethod() => SetLevelRequest::class,
            PingRequest::getMethod() => PingRequest::class,
            GetPromptRequest::getMethod() => GetPromptRequest::class,
            ListPromptsRequest::getMethod() => ListPromptsRequest::class,
            ListResourcesRequest::getMethod() => ListResourcesRequest::class,
            ReadResourceRequest::getMethod() => ReadResourceRequest::class,
            SubscribeRequest::getMethod() => SubscribeRequest::class,
            ListResourceTemplatesRequest::getMethod() => ListResourceTemplatesRequest::class,
            UnsubscribeRequest::getMethod() => UnsubscribeRequest::class,
            ListRootsRequest::getMethod() => ListRootsRequest::class,
            CreateMessageRequest::getMethod() => CreateMessageRequest::class,
            CancelTaskRequest::getMethod() => CancelTaskRequest::class,
            GetTaskRequest::getMethod() => GetTaskRequest::class,
            ListTasksRequest::getMethod() => ListTasksRequest::class,
            GetTaskPayloadRequest::getMethod() => GetTaskPayloadRequest::class,
            CallToolRequest::getMethod() => CallToolRequest::class,
            ListToolsRequest::getMethod() => ListToolsRequest::class,
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
            CancelledNotification::getMethod() => CancelledNotification::class,
            ElicitationCompleteNotification::getMethod() => ElicitationCompleteNotification::class,
            InitializedNotification::getMethod() => InitializedNotification::class,
            LoggingMessageNotification::getMethod() => LoggingMessageNotification::class,
            ProgressNotification::getMethod() => ProgressNotification::class,
            PromptListChangedNotification::getMethod() => PromptListChangedNotification::class,
            ResourceListChangedNotification::getMethod() => ResourceListChangedNotification::class,
            ResourceUpdatedNotification::getMethod() => ResourceUpdatedNotification::class,
            RootsListChangedNotification::getMethod() => RootsListChangedNotification::class,
            TaskStatusNotification::getMethod() => TaskStatusNotification::class,
            ToolListChangedNotification::getMethod() => ToolListChangedNotification::class,
        ];
    }
}
