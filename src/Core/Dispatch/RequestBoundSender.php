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

use Nexus\Mcp\Core\Exception\OutboundRequestsNotSupportedException;
use Nexus\Mcp\Core\Handler\SenderInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\TransportInterface;

/**
 * `SenderInterface` implementation scoped to a single inbound request.
 */
final readonly class RequestBoundSender implements SenderInterface
{
    private SendContext $outboundContext;

    public function __construct(
        private TransportInterface $transport,
        private RequestId $requestId,
    ) {
        $this->outboundContext = new SendContext(relatedRequestId: $this->requestId);
    }

    #[\Override]
    public function sendNotification(JsonRpcNotification $notification): void
    {
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
