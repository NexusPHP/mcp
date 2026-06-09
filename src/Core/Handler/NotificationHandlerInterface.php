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

namespace Nexus\Mcp\Core\Handler;

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;

/**
 * Handles a single inbound JSON-RPC notification.
 *
 * `TMethod` binds the literal returned by `static::getMethod()` on the matching
 * notification class so the registry can store handlers heterogeneously. It
 * does not narrow `handle()`'s parameter, which stays at the wide envelope
 * type.
 *
 * @template-covariant TMethod of non-empty-string
 */
interface NotificationHandlerInterface
{
    /**
     * @param JsonRpcNotification<non-empty-string, array<string, mixed>> $notification
     */
    public function handle(JsonRpcNotification $notification): void;
}
