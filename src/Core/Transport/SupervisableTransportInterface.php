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
 * A transport whose peer runs as a process it owns.
 */
interface SupervisableTransportInterface extends TransportInterface
{
    /**
     * Registers a listener invoked with the peer's exit code (null when it reported none) when the transport
     * tears down without `close()`, after which the instance is spent and a supervisor builds a fresh one.
     *
     * @param \Closure(null|int): void $listener
     */
    public function onUnexpectedExit(\Closure $listener): ListenerHandleInterface;
}
