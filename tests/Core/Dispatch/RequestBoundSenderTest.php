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

namespace Nexus\Mcp\Tests\Core\Dispatch;

use Nexus\Mcp\Core\Dispatch\RequestBoundSender;
use Nexus\Mcp\Core\Exception\OutboundRequestsNotSupportedException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\ProgressNotificationParams;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequestBoundSender::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RequestBoundSenderTest extends TestCase
{
    public function testSendNotificationDelegatesToTransportWithRelatedRequestIdContext(): void
    {
        $transport = new RecordingTransport();
        $requestId = new RequestId('req-1');
        $sender = new RequestBoundSender($transport, $requestId);

        $notification = new ProgressNotification(
            new ProgressNotificationParams(new ProgressToken('tok-1'), 0.5),
        );

        $sender->sendNotification($notification);

        self::assertCount(1, $transport->sent);
        self::assertSame($notification, $transport->sent[0]['message']);
        $context = $transport->sent[0]['context'];
        self::assertNotNull($context);
        self::assertSame($requestId, $context->relatedRequestId);
    }

    public function testSendRequestThrowsTypedExceptionCarryingTheInboundRequestId(): void
    {
        $inboundId = new RequestId(1);
        $sender = new RequestBoundSender(new RecordingTransport(), $inboundId);

        try {
            $sender->sendRequest(new PingRequest(new RequestId(2), new EmptyRequestParams()));
        } catch (OutboundRequestsNotSupportedException $e) {
            self::assertSame($inboundId, $e->requestId);
            self::assertSame(
                'Outbound server-to-client requests are not implemented yet.',
                $e->getMessage(),
            );
            self::assertSame(ProtocolErrorCode::InternalError, OutboundRequestsNotSupportedException::errorCode());
        }
    }
}
