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

namespace Nexus\Mcp\Extension\Tasks\Server;

use Nexus\Mcp\Core\Exception\OutboundRequestsNotSupportedException;
use Nexus\Mcp\Core\Handler\SenderInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Psr\Log\LoggerInterface;

/**
 * Sender for detached task fibers, dropping notifications and refusing requests.
 *
 * @internal
 */
final readonly class DetachedTaskSender implements SenderInterface
{
    public function __construct(private LoggerInterface $logger, private RequestId $requestId)
    {
    }

    #[\Override]
    public function sendNotification(JsonRpcNotification $notification): void
    {
        $this->logger->debug(
            'Dropping a {method} notification from a detached task fiber.',
            ['method' => $notification::getMethod()],
        );
    }

    #[\Override]
    public function sendRequest(JsonRpcRequest $request): never
    {
        throw new OutboundRequestsNotSupportedException($this->requestId);
    }
}
