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

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;

/**
 * In-process JSON-RPC duplex between two `TransportInterface` instances. Each
 * side's `send()` becomes the other side's inbound message. Pre-start inbound
 * envelopes queue and drain on `start()`. `close()` cascades to the peer.
 */
final class InMemoryTransport implements TransportInterface
{
    /**
     * Envelopes the peer's `send()` delivered before this side called `start()`. Drained in arrival order on `start()`.
     *
     * @var list<array<string, mixed>>
     */
    private array $pendingInbound = [];

    private TransportState $state = TransportState::Idle;
    private ?self $peer = null;
    private readonly TransportEvents $events;

    private function __construct()
    {
        $this->events = new TransportEvents();
    }

    /**
     * Returns two linked transports. Each side's `send()` delivers to the
     * other side's `onMessage` listeners. Use one for the server, the other
     * for the client.
     *
     * @return array{self, self}
     */
    public static function createPair(): array
    {
        $a = new self();
        $b = new self();
        $a->peer = $b;
        $b->peer = $a;

        return [$a, $b];
    }

    #[\Override]
    public function start(): void
    {
        match ($this->state) {
            TransportState::Running => throw new TransportAlreadyStartedException(transport: self::class),
            TransportState::Closed => throw new TransportAlreadyClosedException(operation: 'start'),
            TransportState::Idle => null,
        };

        $this->state = TransportState::Running;

        foreach ($this->pendingInbound as $envelope) {
            $this->events->emitMessage($envelope, new ReceiveContext());
        }
    }

    /**
     * `$context`'s `relatedRequestId` is a streamable-HTTP concern with no in-process
     * equivalent. The parameter is accepted for `TransportInterface` conformance and
     * intentionally dropped.
     *
     * @throws \InvalidArgumentException
     * @throws TransportAlreadyClosedException
     * @throws TransportNotStartedException
     */
    #[\Override]
    public function send(JsonRpcMessage $message, ?SendContext $context = null): void
    {
        match ($this->state) {
            TransportState::Idle => throw new TransportNotStartedException(operation: 'send'),
            TransportState::Closed => throw new TransportAlreadyClosedException(operation: 'send'),
            TransportState::Running => null,
        };

        \assert(method_exists($message, 'toArray'), 'In-memory transport requires a JsonRpcMessage exposing toArray().');

        $envelope = $message->toArray();
        Assert::that($envelope)->isMap('In-memory transport: toArray() must return a string-keyed object.');

        $this->peer?->receive($envelope);
    }

    #[\Override]
    public function close(): void
    {
        if (TransportState::Closed === $this->state) {
            return;
        }

        try {
            $this->events->emitDrain();
        } finally {
            $this->state = TransportState::Closed;

            $peer = $this->peer;
            $this->peer = null;

            $peer?->close();

            $this->events->emitClose();
        }
    }

    #[\Override]
    public function onMessage(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onMessage($listener);
    }

    /**
     * In-memory transport has no I/O failure surface, so registered error
     * listeners are accepted for contract conformance but never invoked.
     */
    #[\Override]
    public function onError(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onError($listener);
    }

    #[\Override]
    public function onDrain(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onDrain($listener);
    }

    #[\Override]
    public function onClose(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onClose($listener);
    }

    /**
     * Cross-instance hand-off invoked by the peer's `send()`. Queues into
     * `pendingInbound` while this side is `Idle`, otherwise emits to listeners.
     *
     * @param array<string, mixed> $envelope
     */
    private function receive(array $envelope): void
    {
        if (TransportState::Idle === $this->state) {
            $this->pendingInbound[] = $envelope;

            return;
        }

        $this->events->emitMessage($envelope, new ReceiveContext());
    }
}
