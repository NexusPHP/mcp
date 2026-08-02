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
 * A transport that replaces its own connection in place, so the peer a caller writes to may be a
 * different one from the peer it wrote to a moment ago.
 */
interface ReconnectingTransportInterface extends TransportInterface
{
    /**
     * Registers a listener invoked once for every replacement connection that has started serving.
     * It runs after the close emitted for the connection being replaced, and never for the first
     * connection, which `start()` already reports.
     *
     * A protocol layer holding per-connection state uses this to rebuild it. The fresh peer has no
     * memory of the old one, so anything the caller expects to survive must be re-sent.
     *
     * @param \Closure(): void $listener
     */
    public function onReconnect(\Closure $listener): SubscriptionInterface;

    /**
     * Whether a replacement connection is still on its way. False on a live connection and false once the
     * transport has stopped for good, whether it was closed or gave up.
     *
     * Answers "will what just failed be tried again?", so it is read when a per-connection operation fails,
     * not before one is issued.
     */
    public function isReconnecting(): bool;
}
