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

namespace Nexus\Mcp\Tests\Fixtures\Core\Transport;

use Amp\DeferredFuture;
use Amp\Future;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\CancellableTransportInterface;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\Subscription;
use Nexus\Mcp\Core\Transport\SubscriptionInterface;

/**
 * In-memory implementation of `TransportInterface` for tests. Records every
 * send and exposes hooks to drive the inbound listener chain manually.
 *
 * @internal
 */
final class RecordingTransport implements CancellableTransportInterface
{
    public private(set) bool $started = false;
    public private(set) bool $closed = false;

    /**
     * @var list<array{message: JsonRpcMessage, context: ?SendContext}>
     */
    public private(set) array $sent = [];

    public ?\Throwable $sendError = null;

    /**
     * @var list<\Closure(array<string, mixed>, ReceiveContext): void>
     */
    private array $messageListeners = [];

    /**
     * @var list<\Closure(): void>
     */
    private array $closeListeners = [];

    /**
     * @var list<\Closure(\Throwable): void>
     */
    private array $errorListeners = [];

    /**
     * @var list<\Closure(): void>
     */
    private array $drainListeners = [];

    /**
     * @var list<\Closure(RequestId): void>
     */
    private array $cancelListeners = [];

    /**
     * @var list<DeferredFuture<mixed>>
     */
    private array $sendWaiters = [];

    #[\Override]
    public function start(): void
    {
        $this->started = true;
    }

    #[\Override]
    public function send(JsonRpcMessage $message, ?SendContext $context = null): void
    {
        $this->sent[] = ['message' => $message, 'context' => $context];

        $waiters = $this->sendWaiters;
        $this->sendWaiters = [];

        foreach ($waiters as $waiter) {
            $waiter->complete();
        }

        if (null !== $this->sendError) {
            throw $this->sendError;
        }
    }

    /**
     * @return Future<mixed>
     */
    public function nextSend(): Future
    {
        $deferred = new DeferredFuture();

        $this->sendWaiters[] = $deferred;

        return $deferred->getFuture();
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            foreach ($this->drainListeners as $listener) {
                $listener();
            }
        } finally {
            $this->closed = true;

            foreach ($this->closeListeners as $listener) {
                $listener();
            }
        }
    }

    #[\Override]
    public function onMessage(\Closure $listener): SubscriptionInterface
    {
        $this->messageListeners[] = $listener;

        return new Subscription(function () use ($listener): void {
            $this->messageListeners = array_values(array_filter(
                $this->messageListeners,
                static fn(\Closure $candidate): bool => $candidate !== $listener,
            ));
        });
    }

    #[\Override]
    public function onClose(\Closure $listener): SubscriptionInterface
    {
        $this->closeListeners[] = $listener;

        return new Subscription(function () use ($listener): void {
            $this->closeListeners = array_values(array_filter(
                $this->closeListeners,
                static fn(\Closure $candidate): bool => $candidate !== $listener,
            ));
        });
    }

    #[\Override]
    public function onError(\Closure $listener): SubscriptionInterface
    {
        $this->errorListeners[] = $listener;

        return new Subscription(function () use ($listener): void {
            $this->errorListeners = array_values(array_filter(
                $this->errorListeners,
                static fn(\Closure $candidate): bool => $candidate !== $listener,
            ));
        });
    }

    #[\Override]
    public function onDrain(\Closure $listener): SubscriptionInterface
    {
        $this->drainListeners[] = $listener;

        return new Subscription(function () use ($listener): void {
            $this->drainListeners = array_values(array_filter(
                $this->drainListeners,
                static fn(\Closure $candidate): bool => $candidate !== $listener,
            ));
        });
    }

    #[\Override]
    public function onCancel(\Closure $listener): SubscriptionInterface
    {
        $this->cancelListeners[] = $listener;

        return new Subscription(function () use ($listener): void {
            $this->cancelListeners = array_values(array_filter(
                $this->cancelListeners,
                static fn(\Closure $candidate): bool => $candidate !== $listener,
            ));
        });
    }

    /**
     * Drives the registered `onCancel` listeners, standing in for a peer abandoning a request.
     */
    public function emitCancel(RequestId $id): void
    {
        foreach ($this->cancelListeners as $listener) {
            $listener($id);
        }
    }

    /**
     * Drives the registered `onMessage` listeners with a synthetic envelope.
     *
     * @param array<string, mixed> $envelope
     */
    public function emitMessage(array $envelope, ?ReceiveContext $context = null): void
    {
        $context ??= new ReceiveContext();

        foreach ($this->messageListeners as $listener) {
            $listener($envelope, $context);
        }
    }

    public function emitError(\Throwable $error): void
    {
        foreach ($this->errorListeners as $listener) {
            $listener($error);
        }
    }

    /**
     * Fires the registered `onClose` listeners without touching the `$closed`
     * flag, simulating a transport whose underlying stream signals close more
     * than once.
     */
    public function emitClose(): void
    {
        foreach ($this->closeListeners as $listener) {
            $listener();
        }
    }
}
