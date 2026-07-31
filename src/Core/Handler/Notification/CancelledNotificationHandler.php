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

namespace Nexus\Mcp\Core\Handler\Notification;

use Nexus\Mcp\Core\Dispatch\PendingInboundRequests;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Cancels the in-flight request a `notifications/cancelled` names.
 *
 * @implements NotificationHandlerInterface<'notifications/cancelled'>
 *
 * @internal
 */
final readonly class CancelledNotificationHandler implements NotificationHandlerInterface
{
    public function __construct(private PendingInboundRequests $inboundRequests, private LoggerInterface $logger = new NullLogger())
    {
    }

    #[\Override]
    public function handle(JsonRpcNotification $notification): void
    {
        \assert($notification instanceof CancelledNotification);

        $params = $notification->params;
        $requestId = $params->requestId;

        if (! $this->inboundRequests->cancel($requestId)) {
            // The spec has the receiver ignore an id it does not know: the request may have completed
            // before the notification arrived, or never existed.
            $this->logger->debug(
                'Ignoring cancellation for a request that is not in flight.',
                ['id' => $requestId->id],
            );

            return;
        }

        $this->logger->debug(
            'Cancelled an in-flight request.',
            ['id' => $requestId->id, 'reason' => $params->reason],
        );
    }
}
