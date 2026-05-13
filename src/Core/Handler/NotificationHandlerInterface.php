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
 * @template TNotification of JsonRpcNotification
 */
interface NotificationHandlerInterface
{
    /**
     * @param TNotification $notification
     */
    public function handle(JsonRpcNotification $notification): void;
}
