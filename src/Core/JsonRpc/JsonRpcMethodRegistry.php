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
 * Per-key merge applies when the parser receives user entries: user wins over defaults.
 */
final class JsonRpcMethodRegistry
{
    /**
     * @return array<non-empty-string, class-string<JsonRpcRequest<non-empty-string>>>
     */
    public static function requests(): array
    {
        return [
            Request\PingRequest::method() => Request\PingRequest::class,
        ];
    }

    /**
     * @return array<non-empty-string, class-string<JsonRpcNotification<non-empty-string>>>
     */
    public static function notifications(): array
    {
        return [
            Notification\InitializedNotification::method() => Notification\InitializedNotification::class,
        ];
    }
}
