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

namespace Nexus\Mcp\Tests\Fixtures\Client\Transport;

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Transport\ListenerHandle;
use Nexus\Mcp\Core\Transport\ListenerHandleInterface;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\SupervisableTransportInterface;
use Nexus\Mcp\Core\Transport\TransportEvents;

use function Amp\delay;

/**
 * In-memory `SupervisableTransportInterface` double recording every send.
 *
 * @internal
 */
final class SupervisableRecordingTransport implements SupervisableTransportInterface
{
    public bool $started = false;
    public bool $closed = false;

    /**
     * @var list<array{message: JsonRpcMessage, context: null|SendContext}>
     */
    public array $sent = [];

    public ?\Throwable $startError = null;
    public float $startDelay = 0.0;
    public ?\Throwable $closeError = null;
    public float $closeDelay = 0.0;
    public ?\Throwable $sendError = null;

    /**
     * Runs at the top of every `send()`, before `$sendError` is raised.
     *
     * @var null|\Closure(self): void
     */
    public ?\Closure $onSend = null;

    private readonly TransportEvents $events;

    /**
     * @var array<int, \Closure(null|int): void>
     */
    private array $exitListeners = [];

    public function __construct()
    {
        $this->events = new TransportEvents();
    }

    #[\Override]
    public function start(): void
    {
        if (null !== $this->startError) {
            throw $this->startError;
        }

        if ($this->startDelay > 0.0) {
            delay($this->startDelay);
        }

        $this->started = true;
    }

    #[\Override]
    public function send(JsonRpcMessage $message, ?SendContext $context = null): void
    {
        if (null !== $this->onSend) {
            ($this->onSend)($this);
        }

        if (null !== $this->sendError) {
            throw $this->sendError;
        }

        $this->sent[] = ['message' => $message, 'context' => $context];
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->closeDelay > 0.0) {
            delay($this->closeDelay);
        }

        if (null !== $this->closeError) {
            throw $this->closeError;
        }

        $this->events->emitDrain();
        $this->events->emitClose();
    }

    #[\Override]
    public function onMessage(\Closure $listener): ListenerHandleInterface
    {
        return $this->events->onMessage($listener);
    }

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

    #[\Override]
    public function onUnexpectedExit(\Closure $listener): ListenerHandleInterface
    {
        $id = spl_object_id($listener);
        $this->exitListeners[$id] = $listener;

        return new ListenerHandle(function () use ($id): void {
            unset($this->exitListeners[$id]);
        });
    }

    /**
     * A false `$streamClosesFirst` leaves the streams open, so only an explicit `close()` releases this.
     */
    public function emitUnexpectedExit(?int $exitCode = 1, bool $streamClosesFirst = true): void
    {
        if ($streamClosesFirst) {
            $this->close();
        }

        foreach ($this->exitListeners as $listener) {
            $listener($exitCode);
        }
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function emitMessage(array $envelope, ?ReceiveContext $context = null): void
    {
        $this->events->emitMessage($envelope, $context ?? new ReceiveContext());
    }

    public function emitError(\Throwable $error): void
    {
        $this->events->emitError($error);
    }

    /**
     * Fires the close listeners without touching the `$closed` flag.
     */
    public function emitClose(): void
    {
        $this->events->emitClose();
    }

    public function emitDrain(): void
    {
        $this->events->emitDrain();
    }
}
