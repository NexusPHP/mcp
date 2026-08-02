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
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\Subscription;
use Nexus\Mcp\Core\Transport\SubscriptionInterface;
use Nexus\Mcp\Core\Transport\SupervisableTransportInterface;
use Nexus\Mcp\Core\Transport\TransportEvents;

/**
 * In-memory `SupervisableTransportInterface` for supervision tests. Records sends and exposes hooks to
 * drive each listener chain, including the unexpected-exit signal a real subprocess would raise.
 *
 * @internal
 */
final class SupervisableRecordingTransport implements SupervisableTransportInterface
{
    public private(set) bool $started = false;
    public private(set) bool $closed = false;

    /**
     * @var list<array{message: JsonRpcMessage, context: null|SendContext}>
     */
    public private(set) array $sent = [];

    public ?\Throwable $startError = null;
    public ?\Throwable $closeError = null;
    public ?\Throwable $sendError = null;
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

        $this->started = true;
    }

    #[\Override]
    public function send(JsonRpcMessage $message, ?SendContext $context = null): void
    {
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

        if (null !== $this->closeError) {
            // Mirrors LineDuplex, which drains its background loops before emitting close, so a failure
            // there means the caller never sees the peer's own close event.
            throw $this->closeError;
        }

        $this->events->emitDrain();
        $this->events->emitClose();
    }

    #[\Override]
    public function onMessage(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onMessage($listener);
    }

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

    #[\Override]
    public function onUnexpectedExit(\Closure $listener): SubscriptionInterface
    {
        $id = spl_object_id($listener);
        $this->exitListeners[$id] = $listener;

        return new Subscription(function () use ($id): void {
            unset($this->exitListeners[$id]);
        });
    }

    /**
     * Stands in for the peer dying on its own. The stream EOF and the exit status travel independently in
     * a real transport: with `$streamClosesFirst` the streams tear down first and this transport closes
     * itself, otherwise only the status lands and the streams stay open, as when something else inherited
     * them. In the second case only an explicit `close()` can still release this transport.
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
     * Fires the close listeners without touching the `$closed` flag, standing in for a stream that
     * signals close more than once.
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
