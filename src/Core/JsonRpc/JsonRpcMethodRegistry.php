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
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\Notification\PromptListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceListChangedNotification;
use Nexus\Mcp\Core\Schema\Notification\ResourceUpdatedNotification;
use Nexus\Mcp\Core\Schema\Notification\SubscriptionsAcknowledgedNotification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
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

/**
 * Spec-default method → class maps consumed by `JsonRpcMessageParser`.
 */
final class JsonRpcMethodRegistry
{
    /**
     * Keyed by spec method literal (`completion/complete`, etc.), sorted by key.
     *
     * @return array<non-empty-string, class-string<JsonRpcRequest<non-empty-string, array<string, mixed>>>>
     */
    public static function requests(): array
    {
        return [
            CompleteRequest::getMethod() => CompleteRequest::class,
            ElicitRequest::getMethod() => ElicitRequest::class,
            GetPromptRequest::getMethod() => GetPromptRequest::class,
            ListPromptsRequest::getMethod() => ListPromptsRequest::class,
            ListResourcesRequest::getMethod() => ListResourcesRequest::class,
            ReadResourceRequest::getMethod() => ReadResourceRequest::class,
            ListResourceTemplatesRequest::getMethod() => ListResourceTemplatesRequest::class,
            DiscoverRequest::getMethod() => DiscoverRequest::class,
            SubscriptionsListenRequest::getMethod() => SubscriptionsListenRequest::class,
            CallToolRequest::getMethod() => CallToolRequest::class,
            ListToolsRequest::getMethod() => ListToolsRequest::class,
        ];
    }

    /**
     * Keyed by spec method literal (`notifications/cancelled`, etc.), sorted by key.
     *
     * @return array<non-empty-string, class-string<JsonRpcNotification<non-empty-string, array<string, mixed>>>>
     */
    public static function notifications(): array
    {
        return [
            CancelledNotification::getMethod() => CancelledNotification::class,
            ElicitationCompleteNotification::getMethod() => ElicitationCompleteNotification::class,
            LoggingMessageNotification::getMethod() => LoggingMessageNotification::class,
            ProgressNotification::getMethod() => ProgressNotification::class,
            PromptListChangedNotification::getMethod() => PromptListChangedNotification::class,
            ResourceListChangedNotification::getMethod() => ResourceListChangedNotification::class,
            ResourceUpdatedNotification::getMethod() => ResourceUpdatedNotification::class,
            SubscriptionsAcknowledgedNotification::getMethod() => SubscriptionsAcknowledgedNotification::class,
            ToolListChangedNotification::getMethod() => ToolListChangedNotification::class,
        ];
    }
}
