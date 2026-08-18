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

use Amp\DeferredFuture;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;

/**
 * In-process JSON-RPC duplex between two `TransportInterface` instances.
 */
final class InMemoryTransport implements TransportInterface
{
    /**
     * Envelopes the peer's `send()` delivered before this side called `start()`, drained there in arrival order.
     *
     * @var list<array<string, mixed>>
     */
    private array $pendingInbound = [];

    private TransportState $state = TransportState::Idle;

    /**
     * True from the first `close()` on, which `state` cannot signal as it stays `Running` across the
     * drain so a listener may still send.
     */
    private bool $closing = false;

    /**
     * @var null|\Fiber<mixed, mixed, mixed, mixed> The close owner, `null` while no close is in progress or when {main} owns it
     */
    private ?\Fiber $closingFiber = null;

    /**
     * @var null|DeferredFuture<null>
     */
    private ?DeferredFuture $closeCompletion = null;

    private ?self $peer = null;
    private readonly TransportEvents $events;

    private function __construct()
    {
        $this->events = new TransportEvents();
    }

    /**
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
            $this->deliver($envelope);
        }
    }

    /**
     * `$context` is accepted for `TransportInterface` conformance and dropped, its `relatedRequestId` having
     * no in-process equivalent.
     *
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

        $this->peer?->receive($message->toArray());
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closing) {
            if (\Fiber::getCurrent() === $this->closingFiber) {
                return;
            }

            $this->closeCompletion?->getFuture()->await();

            return;
        }

        $this->closing = true;
        $this->closingFiber = \Fiber::getCurrent();

        /** @var DeferredFuture<null> $completion */
        $completion = new DeferredFuture();
        $this->closeCompletion = $completion;

        try {
            $this->events->emitDrain();
        } finally {
            $this->state = TransportState::Closed;

            try {
                $peer = $this->peer;
                $this->peer = null;

                $peer?->close();

                $this->events->emitClose();
            } finally {
                $completion->complete();
            }
        }
    }

    #[\Override]
    public function onMessage(\Closure $listener): ListenerHandleInterface
    {
        return $this->events->onMessage($listener);
    }

    /**
     * An in-memory transport has no I/O failure surface, so the error listeners see only faults thrown
     * by this side's own message listeners.
     */
    #[\Override]
    public function onError(\Closure $listener): ListenerHandleInterface
    {
        return $this->events->onError($listener);
    }

    #[\Override]
    public function onDrain(\Closure $listener): ListenerHandleInterface
    {
        return $this->events->onDrain($listener);
    }

    #[\Override]
    public function onClose(\Closure $listener): ListenerHandleInterface
    {
        return $this->events->onClose($listener);
    }

    /**
     * Cross-instance hand-off invoked by the peer's `send()`, queuing into `pendingInbound` while this side
     * is `Idle`.
     *
     * @param array<string, mixed> $envelope
     */
    private function receive(array $envelope): void
    {
        if (TransportState::Idle === $this->state) {
            $this->pendingInbound[] = $envelope;

            return;
        }

        $this->deliver($envelope);
    }

    /**
     * Emits an inbound envelope, keeping a listener fault on this side instead of surfacing it through
     * the peer's `send()`.
     *
     * @param array<string, mixed> $envelope
     */
    private function deliver(array $envelope): void
    {
        try {
            $this->events->emitMessage($envelope, new ReceiveContext());
        } catch (\Throwable $e) {
            $this->events->emitError($e);
        }
    }
}
