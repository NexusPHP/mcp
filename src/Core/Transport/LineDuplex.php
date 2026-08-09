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

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableBuffer;
use Amp\ByteStream\WritableStream;
use Amp\DeferredFuture;
use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\Error\ParseError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * Line-framed JSON-RPC pipeline shared by the stdio server and client transports.
 *
 * @internal
 */
final class LineDuplex
{
    private TransportState $state = TransportState::Idle;

    /**
     * Re-entry guard for `close()`, which `state` cannot serve as it stays `Running` across the drain so a
     * listener may still send.
     */
    private bool $closing = false;

    private readonly TransportEvents $events;
    private ReadableStream $readable;
    private WritableStream $writable;

    /**
     * @var null|\Fiber<mixed, mixed, mixed, mixed>
     */
    private ?\Fiber $readLoopFiber = null;

    /**
     * @var null|DeferredFuture<null>
     */
    private ?DeferredFuture $readLoopCompletion = null;

    /**
     * @var list<DeferredFuture<mixed>>
     */
    private array $sideChannelCompletions = [];

    /**
     * @var list<ReadableStream>
     */
    private array $sideChannelSources = [];

    /**
     * @var array<int, null|\Fiber<mixed, mixed, mixed, mixed>>
     */
    private array $sideChannelFibers = [];

    /**
     * @param class-string                                $hostTransport
     * @param non-empty-string                            $label
     * @param null|(\Closure(JsonRpcErrorResponse): void) $onParseFailure
     * @param null|\Closure(): void                       $onBeforeClose  Tears the outbound side down after
     *                                                                    the drain, so a listener's last
     *                                                                    send still reaches the peer
     */
    public function __construct(
        private readonly string $hostTransport,
        private readonly string $label,
        private readonly LoggerInterface $logger,
        private readonly int $maxLineBytes = LineReader::DEFAULT_MAX_LINE_BYTES,
        private readonly ?\Closure $onParseFailure = null,
        private readonly ?\Closure $onBeforeClose = null,
    ) {
        $this->readable = new ReadableBuffer('');
        $this->writable = new WritableBuffer();
        $this->events = new TransportEvents(
            onChange: function (string $kind, string $action, int $count): void {
                $verb = match ($action) {
                    'register' => 'registered',
                    'dispose' => 'disposed',
                };
                $article = 'error' === $kind ? 'an' : 'a';
                $this->logger->debug(
                    '{label} transport {verb} {article} {kind} listener. {count} active.',
                    ['label' => $this->label, 'verb' => $verb, 'article' => $article, 'kind' => $kind, 'count' => $count],
                );
            },
        );
    }

    /**
     * @throws TransportAlreadyClosedException
     * @throws TransportAlreadyStartedException
     */
    public function start(ReadableStream $readable, WritableStream $writable): void
    {
        match ($this->state) {
            TransportState::Running => throw new TransportAlreadyStartedException(transport: $this->hostTransport),
            TransportState::Closed => throw new TransportAlreadyClosedException(operation: 'start'),
            TransportState::Idle => null,
        };

        $this->state = TransportState::Running;
        $this->readable = $readable;
        $this->writable = $writable;
        $this->logger->info('{label} transport started.', ['label' => $this->label]);

        $this->readLoopCompletion = new DeferredFuture();

        EventLoop::queue($this->readLoop(...));
    }

    /**
     * @throws \JsonException
     * @throws TransportAlreadyClosedException
     * @throws TransportNotStartedException
     */
    public function send(JsonRpcMessage $message): void
    {
        match ($this->state) {
            TransportState::Idle => throw new TransportNotStartedException(operation: 'send'),
            TransportState::Closed => throw new TransportAlreadyClosedException(operation: 'send'),
            TransportState::Running => null,
        };

        try {
            $this->writable->write(json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
            $this->logger->debug(
                '{label} transport sent {kind}.',
                ['label' => $this->label, 'kind' => self::describe($message)],
            );
        } catch (\Throwable $e) {
            if (TransportState::Closed === $this->state) {
                $this->logger->debug(
                    '{label} transport skipped sending {kind}. Transport was concurrently closed.',
                    ['label' => $this->label, 'kind' => self::describe($message), 'exception' => $e],
                );

                throw new TransportAlreadyClosedException(operation: 'send', previous: $e);
            }

            $this->logger->error(
                '{label} transport failed to send {kind}. Closing.',
                ['label' => $this->label, 'kind' => self::describe($message), 'exception' => $e],
            );
            $this->close();

            throw $e;
        }
    }

    public function close(): void
    {
        if (TransportState::Closed === $this->state || $this->closing) {
            return;
        }

        $this->closing = true;
        $this->logger->info(
            '{label} transport closing from {priorState} state.',
            ['label' => $this->label, 'priorState' => $this->state->name],
        );

        try {
            $this->readable->close();

            foreach ($this->sideChannelSources as $source) {
                $source->close();
            }

            $this->drainBackgroundLoops();
            $this->events->emitDrain();
        } finally {
            $this->settle();
        }
    }

    /**
     * @param \Closure(string): void $onLine
     */
    public function forwardLines(ReadableStream $source, \Closure $onLine): void
    {
        $completion = new DeferredFuture();
        $this->sideChannelCompletions[] = $completion;
        $this->sideChannelSources[] = $source;

        EventLoop::queue(function () use ($source, $onLine, $completion): void {
            $this->sideChannelFibers[spl_object_id($completion)] = \Fiber::getCurrent();
            $reader = new LineReader($source, $this->maxLineBytes);

            try {
                foreach ($reader->getLines() as $line) {
                    $onLine($line);
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '{label} transport side-channel loop failed.',
                    ['label' => $this->label, 'exception' => $e],
                );
            } finally {
                $completion->complete();
                unset($this->sideChannelFibers[spl_object_id($completion)]);
                $this->sideChannelCompletions = array_values(array_filter(
                    $this->sideChannelCompletions,
                    static fn(DeferredFuture $candidate): bool => $candidate !== $completion,
                ));
            }
        });
    }

    /**
     * @param \Closure(array<string, mixed>, ReceiveContext): void $listener
     */
    public function onMessage(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onMessage($listener);
    }

    /**
     * @param \Closure(\Throwable): void $listener
     */
    public function onError(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onError($listener);
    }

    /**
     * @param \Closure(): void $listener
     */
    public function onDrain(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onDrain($listener);
    }

    /**
     * @param \Closure(): void $listener
     */
    public function onClose(\Closure $listener): SubscriptionInterface
    {
        return $this->events->onClose($listener);
    }

    /**
     * Outbound teardown and the close transition, reached however the drain ended.
     */
    private function settle(): void
    {
        try {
            if (null !== $this->onBeforeClose) {
                ($this->onBeforeClose)();
            }
        } finally {
            $this->state = TransportState::Closed;

            $this->events->emitClose();
            $this->logger->info('{label} transport closed.', ['label' => $this->label]);
        }
    }

    /**
     * Blocks until the backgrounded loops finish, so a force-close leaves no fiber suspended on a read.
     */
    private function drainBackgroundLoops(): void
    {
        $current = \Fiber::getCurrent();

        // A fiber that triggers close() mid-run must not await its own completion, which only it fulfils on return.
        if ($current !== $this->readLoopFiber) {
            $this->readLoopCompletion?->getFuture()->await();
        }

        foreach ($this->sideChannelCompletions as $completion) {
            if (null === $current || ($this->sideChannelFibers[spl_object_id($completion)] ?? null) !== $current) {
                $completion->getFuture()->await();
            }
        }
    }

    private function readLoop(): void
    {
        $this->readLoopFiber = \Fiber::getCurrent();
        $reader = new LineReader($this->readable, $this->maxLineBytes);

        try {
            foreach ($reader->getLines() as $line) {
                $this->processLine($line);
            }
        } catch (\Throwable $e) {
            if (! $this->closing) {
                $this->logger->error(
                    '{label} transport read loop failed. Closing.',
                    ['label' => $this->label, 'exception' => $e],
                );
                $this->events->emitError($e);
            }
        } finally {
            $this->readLoopCompletion?->complete();
            $this->close();
        }
    }

    private function processLine(string $line): void
    {
        try {
            $decodedJson = json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(
                '{label} transport rejected malformed JSON line.',
                ['label' => $this->label, 'exception' => $e],
            );
            $this->reportParseFailure(new JsonRpcErrorResponse(
                id: null,
                error: new ParseError(message: ParseError::DEFAULT_MESSAGE),
            ));

            return;
        }

        try {
            Assert::that($decodedJson)->isMap('JSON-RPC envelope must be a JSON object, {type} given.');
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning(
                '{label} transport rejected a non-object envelope.',
                ['label' => $this->label, 'exception' => $e],
            );
            $this->events->emitError($e);
            $this->reportParseFailure(new JsonRpcErrorResponse(
                id: null,
                error: new InvalidRequestError(message: InvalidRequestError::DEFAULT_MESSAGE),
            ));

            return;
        }

        try {
            $this->logger->debug(
                '{label} transport received a JSON-RPC envelope.',
                ['label' => $this->label],
            );
            $this->events->emitMessage($decodedJson, new ReceiveContext());
        } catch (\Throwable $e) {
            $this->events->emitError($e);
        }
    }

    private function reportParseFailure(JsonRpcErrorResponse $response): void
    {
        if (null === $this->onParseFailure) {
            return;
        }

        ($this->onParseFailure)($response);
    }

    private static function describe(JsonRpcMessage $message): string
    {
        return match (true) {
            $message instanceof JsonRpcRequest => \sprintf('"%s" request with id "%s"', $message::getMethod(), $message->id->id),
            $message instanceof JsonRpcNotification => \sprintf('"%s" notification', $message::getMethod()),
            default => 'a response envelope',
        };
    }
}
