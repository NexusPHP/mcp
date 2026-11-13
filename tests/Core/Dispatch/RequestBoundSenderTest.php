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

use Amp\DeferredCancellation;
use Nexus\Mcp\Core\Dispatch\RequestBoundSender;
use Nexus\Mcp\Core\Exception\OutboundRequestsNotSupportedException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\ProgressNotificationParams;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(RequestBoundSender::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RequestBoundSenderTest extends AbstractMcpTestCase
{
    public function testSendNotificationDelegatesToTransportWithRelatedRequestIdContext(): void
    {
        $transport = new RecordingTransport();
        $requestId = new RequestId(id: 'req-1');
        $sender = new RequestBoundSender($transport, $requestId);

        $notification = new ProgressNotification(
            params: new ProgressNotificationParams(progressToken: new ProgressToken(token: 'tok-1'), progress: 0.5),
        );

        $sender->sendNotification($notification);

        self::assertCount(1, $transport->sent);
        self::assertSame($notification, $transport->sent[0]['message']);
        $context = $transport->sent[0]['context'];
        self::assertNotNull($context);
        self::assertSame($requestId, $context->relatedRequestId);
    }

    public function testSendNotificationDropsProgressNotAboveTheLastSent(): void
    {
        $transport = new RecordingTransport();
        $sender = new RequestBoundSender($transport, new RequestId(id: 1));

        foreach ([2.0, 1.0, 2.0, 3.0] as $progress) {
            $sender->sendNotification(self::buildProgress($progress));
        }

        self::assertSame([2.0, 3.0], self::readProgressValues($transport));
    }

    public function testSendNotificationDropsProgressOnceTheRequestHasEnded(): void
    {
        $transport = new RecordingTransport();
        $source = new DeferredCancellation();
        $sender = new RequestBoundSender($transport, new RequestId(id: 1), $source->getCancellation());

        $sender->sendNotification(self::buildProgress(1.0));
        $source->cancel();
        $sender->sendNotification(self::buildProgress(2.0));
        $sender->sendNotification(new ToolListChangedNotification(params: new EmptyNotificationParams()));

        self::assertSame([1.0], self::readProgressValues($transport));
        self::assertCount(2, $transport->sent);
        self::assertInstanceOf(ToolListChangedNotification::class, $transport->sent[1]['message']);
    }

    public function testSendRequestThrowsTypedExceptionCarryingTheInboundRequestId(): void
    {
        $inboundId = new RequestId(id: 1);
        $sender = new RequestBoundSender(new RecordingTransport(), $inboundId);

        try {
            $sender->sendRequest(new DiscoverRequest(
                id: new RequestId(id: 2),
                params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()),
            ));
        } catch (OutboundRequestsNotSupportedException $e) {
            self::assertSame($inboundId, $e->requestId);
            self::assertSame(
                'Outbound server-to-client requests are not implemented yet.',
                $e->getMessage(),
            );
            self::assertSame(ProtocolErrorCode::InternalError, OutboundRequestsNotSupportedException::getErrorCode());
        }
    }

    private static function buildProgress(float $progress): ProgressNotification
    {
        return new ProgressNotification(
            params: new ProgressNotificationParams(progressToken: new ProgressToken(token: 'tok-1'), progress: $progress),
        );
    }

    /**
     * @return list<float>
     */
    private static function readProgressValues(RecordingTransport $transport): array
    {
        $values = [];

        foreach ($transport->sent as ['message' => $message]) {
            if ($message instanceof ProgressNotification) {
                $values[] = $message->params->progress;
            }
        }

        return $values;
    }
}
