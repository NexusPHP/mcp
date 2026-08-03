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
 * A transport whose in-flight work can be stopped one request at a time, rather than only wholesale by
 * `close()`. The inbound counterpart is `CancellableTransportInterface`, which reports a peer abandoning
 * a request the caller is serving.
 */
interface AbortableTransportInterface extends TransportInterface
{
    /**
     * Stops whatever this transport still has in flight for `$id` and releases it. Naming a request that
     * was never sent, already answered, or already aborted does nothing.
     *
     * The caller has given up on the response, so the abort is not reported as a fault: nothing reaches
     * `onError()` and no reply arrives for that id afterwards. Telling the peer is the protocol layer's
     * job, through `notifications/cancelled`.
     */
    public function abort(RequestId $id): void;
}
