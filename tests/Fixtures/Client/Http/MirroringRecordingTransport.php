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

namespace Nexus\Mcp\Tests\Fixtures\Client\Http;

use Amp\Future;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Transport\ParameterHeaderMirroringInterface;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\SubscriptionInterface;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;

/**
 * `RecordingTransport` wearing the parameter-header mirroring marker.
 *
 * @internal
 */
final class MirroringRecordingTransport implements ParameterHeaderMirroringInterface
{
    public RecordingTransport $recorder;

    /**
     * @var list<array{message: JsonRpcMessage, context: null|SendContext}>
     */
    public array $sent = [];

    public function __construct()
    {
        $this->recorder = new RecordingTransport();
    }

    #[\Override]
    public function start(): void
    {
        $this->recorder->start();
    }

    #[\Override]
    public function send(JsonRpcMessage $message, ?SendContext $context = null): void
    {
        $this->recorder->send($message, $context);
        $this->sent = $this->recorder->sent;
    }

    #[\Override]
    public function close(): void
    {
        $this->recorder->close();
    }

    #[\Override]
    public function onMessage(\Closure $listener): SubscriptionInterface
    {
        return $this->recorder->onMessage($listener);
    }

    #[\Override]
    public function onError(\Closure $listener): SubscriptionInterface
    {
        return $this->recorder->onError($listener);
    }

    #[\Override]
    public function onDrain(\Closure $listener): SubscriptionInterface
    {
        return $this->recorder->onDrain($listener);
    }

    #[\Override]
    public function onClose(\Closure $listener): SubscriptionInterface
    {
        return $this->recorder->onClose($listener);
    }

    /**
     * @return Future<mixed>
     */
    public function nextSend(): Future
    {
        return $this->recorder->nextSend();
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function emitMessage(array $envelope, ?ReceiveContext $context = null): void
    {
        $this->recorder->emitMessage($envelope, $context);
    }
}
