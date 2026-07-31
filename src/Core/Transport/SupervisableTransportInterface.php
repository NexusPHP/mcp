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
 * A transport whose peer runs as a process it owns, and which reports that the peer stopped serving
 * without the shutdown having been requested.
 */
interface SupervisableTransportInterface extends TransportInterface
{
    /**
     * Registers a listener invoked with the peer's exit code when the transport tears down without
     * `close()` having been called, whether the peer exited on its own or stopped serving and was
     * killed. Calling `close()` notifies nobody.
     *
     * The reporting instance is spent once this fires: a supervisor respawns by building a fresh
     * transport, not by restarting this one. The exit code is null when the peer ended without
     * reporting a status.
     *
     * @param \Closure(null|int): void $listener
     */
    public function onUnexpectedExit(\Closure $listener): SubscriptionInterface;
}
