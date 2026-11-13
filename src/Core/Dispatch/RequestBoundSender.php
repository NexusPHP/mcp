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

namespace Nexus\Mcp\Core\Dispatch;

use Amp\Cancellation;
use Amp\NullCancellation;
use Nexus\Mcp\Core\Exception\OutboundRequestsNotSupportedException;
use Nexus\Mcp\Core\Handler\SenderInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\TransportInterface;

/**
 * `SenderInterface` implementation scoped to a single inbound request.
 */
final class RequestBoundSender implements SenderInterface
{
    private readonly SendContext $outboundContext;
    private ?float $lastProgress = null;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly RequestId $requestId,
        private readonly Cancellation $cancellation = new NullCancellation(),
    ) {
        $this->outboundContext = new SendContext(relatedRequestId: $this->requestId);
    }

    /**
     * Progress: a progress notification not above the last one sent, or sent once the request has ended, is dropped.
     */
    #[\Override]
    public function sendNotification(JsonRpcNotification $notification): void
    {
        if ($notification instanceof ProgressNotification) {
            $progress = $notification->params->progress;

            if (
                $this->cancellation->isRequested()
                || (null !== $this->lastProgress && $progress <= $this->lastProgress)
            ) {
                return;
            }

            $this->lastProgress = $progress;
        }

        $this->transport->send($notification, $this->outboundContext);
    }

    /**
     * The spec defines no server-initiated request, so this always rejects.
     */
    #[\Override]
    public function sendRequest(JsonRpcRequest $request): never
    {
        throw new OutboundRequestsNotSupportedException($this->requestId);
    }
}
