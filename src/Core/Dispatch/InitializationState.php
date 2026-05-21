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

namespace Nexus\Mcp\Core\Dispatch;

/**
 * Lifecycle states tracked by the handshake gates on both sides.
 *
 * @internal
 */
enum InitializationState
{
    /**
     * No `initialize` request has been sent or received yet.
     */
    case AwaitingInitialize;

    /**
     * `initialize` request is in flight. On the server, accepted and the handler queued (may
     * not have run yet). On the client, sent and awaiting the peer's `InitializeResult`.
     * Blocks a second `initialize` from either side.
     */
    case InitializeInFlight;

    /**
     * Server-only: `initialize` handler returned successfully. Awaiting `notifications/initialized`.
     */
    case InitializeCompleted;

    /**
     * Handshake done. The session may proceed.
     */
    case Initialized;
}
