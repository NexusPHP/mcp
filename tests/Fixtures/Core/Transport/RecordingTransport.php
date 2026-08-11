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
use Nexus\Mcp\Core\Transport\AbortableTransportInterface;
use Nexus\Mcp\Core\Transport\CancellableTransportInterface;
use Nexus\Mcp\Core\Transport\ListenerHandle;
use Nexus\Mcp\Core\Transport\ListenerHandleInterface;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;

/**
 * In-memory `TransportInterface` double recording every send.
 *
 * @internal
 */
final class RecordingTransport implements AbortableTransportInterface, CancellableTransportInterface
{
    public bool $started = false;
    public bool $closed = false;

    /**
     * @var list<array{message: JsonRpcMessage, context: null|SendContext}>
     */
    public array $sent = [];

    /**
     * @var list<int|non-empty-string>
     */
    public array $aborted = [];

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
    public function onMessage(\Closure $listener): ListenerHandleInterface
    {
        $this->messageListeners[] = $listener;

        return new ListenerHandle(function () use ($listener): void {
            $this->messageListeners = array_values(array_filter(
                $this->messageListeners,
                static fn(\Closure $candidate): bool => $candidate !== $listener,
            ));
        });
    }

    #[\Override]
    public function onClose(\Closure $listener): ListenerHandleInterface
    {
        $this->closeListeners[] = $listener;

        return new ListenerHandle(function () use ($listener): void {
            $this->closeListeners = array_values(array_filter(
                $this->closeListeners,
                static fn(\Closure $candidate): bool => $candidate !== $listener,
            ));
        });
    }

    #[\Override]
    public function onError(\Closure $listener): ListenerHandleInterface
    {
        $this->errorListeners[] = $listener;

        return new ListenerHandle(function () use ($listener): void {
            $this->errorListeners = array_values(array_filter(
                $this->errorListeners,
                static fn(\Closure $candidate): bool => $candidate !== $listener,
            ));
        });
    }

    #[\Override]
    public function onDrain(\Closure $listener): ListenerHandleInterface
    {
        $this->drainListeners[] = $listener;

        return new ListenerHandle(function () use ($listener): void {
            $this->drainListeners = array_values(array_filter(
                $this->drainListeners,
                static fn(\Closure $candidate): bool => $candidate !== $listener,
            ));
        });
    }

    #[\Override]
    public function onCancel(\Closure $listener): ListenerHandleInterface
    {
        $this->cancelListeners[] = $listener;

        return new ListenerHandle(function () use ($listener): void {
            $this->cancelListeners = array_values(array_filter(
                $this->cancelListeners,
                static fn(\Closure $candidate): bool => $candidate !== $listener,
            ));
        });
    }

    public function emitCancel(RequestId $id): void
    {
        foreach ($this->cancelListeners as $listener) {
            $listener($id);
        }
    }

    /**
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
     * Fires the registered `onClose` listeners without touching the `$closed` flag.
     */
    public function emitClose(): void
    {
        foreach ($this->closeListeners as $listener) {
            $listener();
        }
    }

    #[\Override]
    public function abort(RequestId $id): void
    {
        $this->aborted[] = $id->id;
    }
}
