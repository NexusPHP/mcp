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
 */
interface TransportInterface
{
    /**
     * Begins consuming inbound envelopes from the peer, returning immediately.
     *
     * @throws TransportAlreadyStartedException
     * @throws TransportAlreadyClosedException
     */
    public function start(): void;

    /**
     * Enqueues an outbound JSON-RPC message to the peer, close listeners firing before a write failure is rethrown.
     *
     * @throws TransportNotStartedException
     * @throws TransportAlreadyClosedException
     * @throws \Throwable
     */
    public function send(JsonRpcMessage $message, ?SendContext $context = null): void;

    /**
     * Closes the connection once, firing `onClose()` after the underlying streams close, which an
     * implementation MUST also do after a fatal error since `Server::run()` blocks on that signal.
     */
    public function close(): void;

    /**
     * A transport multiplexing several peers MUST namespace or rewrite inbound request ids before
     * emitting them, since the protocol layer correlates and cancels by id alone.
     *
     * @param \Closure(array<string, mixed>, ReceiveContext): void $listener
     */
    public function onMessage(\Closure $listener): ListenerHandleInterface;

    /**
     * @param \Closure(\Throwable): void $listener
     */
    public function onError(\Closure $listener): ListenerHandleInterface;

    /**
     * Register a drain listener that fires before `close()`.
     *
     * @param \Closure(): void $listener
     */
    public function onDrain(\Closure $listener): ListenerHandleInterface;

    /**
     * @param \Closure(): void $listener
     */
    public function onClose(\Closure $listener): ListenerHandleInterface;
}
