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
 * A transport whose in-flight work can be stopped one request at a time rather than only wholesale by `close()`.
 */
interface AbortableTransportInterface extends TransportInterface
{
    /**
     * Stops whatever this transport still has in flight for `$id`: a no-op for an id it does not hold, never
     * reported through `onError()`, and no substitute for the protocol layer's `notifications/cancelled`.
     */
    public function abort(RequestId $id): void;
}
