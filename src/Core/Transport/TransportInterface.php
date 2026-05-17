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

use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;

/**
 * Bidirectional JSON-RPC envelope duplex between this SDK and a connected peer.
 *
 * - Listeners fire in registration order.
 * - A throw aborts the chain.
 * - A dispose mid-dispatch applies on the next emit.
 */
interface TransportInterface
{
    /**
     * Begins consuming inbound envelopes from the peer. Returns immediately.
     *
     * @throws TransportAlreadyStartedException
     * @throws TransportAlreadyClosedException
     */
    public function start(): void;

    /**
     * Enqueues an outbound JSON-RPC message to the peer.
     *
     * On write failure, close listeners fire before the exception is rethrown.
     *
     * @throws TransportNotStartedException
     * @throws TransportAlreadyClosedException
     * @throws \Throwable
     */
    public function send(JsonRpcMessage $message, ?SendContext $context = null): void;

    /**
     * Closes the connection. The `onClose()` listener fires once after the
     * underlying streams are closed. Subsequent calls are no-ops.
     *
     * Implementations MUST guarantee that `onClose()` listeners fire after a
     * fatal error too, not only after explicit `close()`. `Server::run()`
     * blocks on the close signal, so a transport that raises errors without
     * eventually closing would hang the server loop.
     */
    public function close(): void;

    /**
     * Returns the transport-issued session identifier, or null when the
     * transport carries no session (e.g. stdio).
     */
    public function sessionId(): ?string;

    /**
     * Register an inbound-envelope listener.
     *
     * @param \Closure(array<string, mixed>): void $listener
     */
    public function onMessage(\Closure $listener): SubscriptionInterface;

    /**
     * Register a close listener.
     *
     * @param \Closure(): void $listener
     */
    public function onClose(\Closure $listener): SubscriptionInterface;

    /**
     * Register an error listener.
     *
     * @param \Closure(\Throwable): void $listener
     */
    public function onError(\Closure $listener): SubscriptionInterface;

    /**
     * Register a drain listener that fires before `close()`.
     *
     * @param \Closure(): void $listener
     */
    public function onDrain(\Closure $listener): SubscriptionInterface;
}
