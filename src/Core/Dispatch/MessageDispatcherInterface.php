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

use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\TransportInterface;

/**
 * Per-envelope inbound dispatch contract shared by the Server and Client
 * runtimes. Each side owns one implementation and exposes it to a
 * transport's `onMessage` / `onDrain` listeners.
 */
interface MessageDispatcherInterface
{
    /**
     * Processes a single inbound JSON-RPC envelope.
     *
     * @param array<string, mixed> $envelope
     */
    public function dispatch(array $envelope, TransportInterface $transport, ReceiveContext $context): void;

    /**
     * Awaits every in-flight dispatch coroutine spawned by `dispatch()`.
     */
    public function flushPending(): void;
}
