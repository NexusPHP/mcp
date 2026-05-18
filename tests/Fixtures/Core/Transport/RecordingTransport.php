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
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\Subscription;
use Nexus\Mcp\Core\Transport\SubscriptionInterface;
use Nexus\Mcp\Core\Transport\TransportInterface;

/**
 * In-memory implementation of `TransportInterface` for tests. Records every
 * send and exposes hooks to drive the inbound listener chain manually.
 *
 * @internal
 */
final class RecordingTransport implements TransportInterface
{
    public private(set) bool $started = false;
    public private(set) bool $closed = false;

    /**
     * @var list<array{message: JsonRpcMessage, context: ?SendContext}>
     */
    public private(set) array $sent = [];

    public ?\Throwable $sendError = null;

    /**
     * @var list<\Closure(array<string, mixed>): void>
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
     * @var list<DeferredFuture<mixed>>
     */
    private array $sendWaiters = [];

    public function __construct(private readonly ?string $sessionId = null)
    {
    }

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
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    #[\Override]
    public function label(): string
    {
        return 'recording';
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

    /**
     * Drives the registered `onMessage` listeners with a synthetic envelope.
     *
     * @param array<string, mixed> $envelope
     */
    public function emitMessage(array $envelope): void
    {
        foreach ($this->messageListeners as $listener) {
            $listener($envelope);
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
