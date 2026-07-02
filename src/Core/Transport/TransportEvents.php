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

/**
 * Listener bookkeeping shared by transports. Owns the four event buckets
 * (`message`, `error`, `drain`, `close`) and fan-out.
 *
 * @internal
 */
final class TransportEvents
{
    /**
     * @var array<int, \Closure(array<string, mixed>, ReceiveContext): void>
     */
    private array $messageListeners = [];

    /**
     * @var array<int, \Closure(\Throwable): void>
     */
    private array $errorListeners = [];

    /**
     * @var array<int, \Closure(): void>
     */
    private array $drainListeners = [];

    /**
     * @var array<int, \Closure(): void>
     */
    private array $closeListeners = [];

    /**
     * @param null|\Closure('close'|'drain'|'error'|'message', 'dispose'|'register', int<0, max>): void $onChange
     */
    public function __construct(private readonly ?\Closure $onChange = null)
    {
    }

    /**
     * @param \Closure(array<string, mixed>, ReceiveContext): void $listener
     */
    public function onMessage(\Closure $listener): SubscriptionInterface
    {
        $id = spl_object_id($listener);
        $this->messageListeners[$id] = $listener;
        $this->notify('message', 'register', \count($this->messageListeners));

        return new Subscription(function () use ($id): void {
            unset($this->messageListeners[$id]);
            $this->notify('message', 'dispose', \count($this->messageListeners));
        });
    }

    /**
     * @param \Closure(\Throwable): void $listener
     */
    public function onError(\Closure $listener): SubscriptionInterface
    {
        $id = spl_object_id($listener);
        $this->errorListeners[$id] = $listener;
        $this->notify('error', 'register', \count($this->errorListeners));

        return new Subscription(function () use ($id): void {
            unset($this->errorListeners[$id]);
            $this->notify('error', 'dispose', \count($this->errorListeners));
        });
    }

    /**
     * @param \Closure(): void $listener
     */
    public function onDrain(\Closure $listener): SubscriptionInterface
    {
        $id = spl_object_id($listener);
        $this->drainListeners[$id] = $listener;
        $this->notify('drain', 'register', \count($this->drainListeners));

        return new Subscription(function () use ($id): void {
            unset($this->drainListeners[$id]);
            $this->notify('drain', 'dispose', \count($this->drainListeners));
        });
    }

    /**
     * @param \Closure(): void $listener
     */
    public function onClose(\Closure $listener): SubscriptionInterface
    {
        $id = spl_object_id($listener);
        $this->closeListeners[$id] = $listener;
        $this->notify('close', 'register', \count($this->closeListeners));

        return new Subscription(function () use ($id): void {
            unset($this->closeListeners[$id]);
            $this->notify('close', 'dispose', \count($this->closeListeners));
        });
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function emitMessage(array $envelope, ReceiveContext $context): void
    {
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

    public function emitDrain(): void
    {
        foreach ($this->drainListeners as $listener) {
            $listener();
        }
    }

    public function emitClose(): void
    {
        foreach ($this->closeListeners as $listener) {
            $listener();
        }
    }

    /**
     * @param 'close'|'drain'|'error'|'message' $kind
     * @param 'dispose'|'register'              $action
     * @param int<0, max>                       $count
     */
    private function notify(string $kind, string $action, int $count): void
    {
        if (null !== $this->onChange) {
            ($this->onChange)($kind, $action, $count);
        }
    }
}
