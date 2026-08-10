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
use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handler for `notifications/cancelled`, cancelling the in-flight request it names.
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
            $this->logger->debug(
                'Ignoring a cancellation naming a request that is not in flight or is already cancelled.',
                ['id' => SafeDisplay::sanitiseId($requestId->id)],
            );

            return;
        }

        $this->logger->debug(
            'Cancelled an in-flight request.',
            [
                'id' => SafeDisplay::sanitiseId($requestId->id),
                'reason' => null === $params->reason ? null : SafeDisplay::sanitiseCause($params->reason),
            ],
        );
    }
}
