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

namespace Nexus\Mcp\Core\Transport;

/**
 * A transport that replaces its own connection in place.
 */
interface ReconnectingTransportInterface extends TransportInterface
{
    /**
     * Registers a listener invoked once for every replacement connection that has started serving, after the
     * close for the one it replaces and never for the first.
     *
     * @param \Closure(): void $listener
     */
    public function onReconnect(\Closure $listener): ListenerHandleInterface;

    /**
     * Whether a replacement connection is still on its way, read when a per-connection operation fails
     * rather than before one is issued.
     */
    public function isReconnecting(): bool;
}
