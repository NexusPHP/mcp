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

use Nexus\Mcp\Core\Schema\RequestId;

/**
 * A transport that can observe a peer abandoning an in-flight request without saying so in a message,
 * such as an SSE consumer closing its stream.
 */
interface CancellableTransportInterface extends TransportInterface
{
    /**
     * Registers a listener invoked with the id of a request the peer abandoned.
     *
     * @param \Closure(RequestId): void $listener
     */
    public function onCancel(\Closure $listener): SubscriptionInterface;
}
