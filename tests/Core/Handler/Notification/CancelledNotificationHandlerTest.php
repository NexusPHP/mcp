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

namespace Nexus\Mcp\Tests\Core\Handler\Notification;

use Nexus\Mcp\Core\Dispatch\PendingInboundRequests;
use Nexus\Mcp\Core\Handler\Notification\CancelledNotificationHandler;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\CancelledNotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * @internal
 */
#[CoversClass(CancelledNotificationHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CancelledNotificationHandlerTest extends TestCase
{
    public function testCancelsTheRequestTheNotificationNames(): void
    {
        $inboundRequests = new PendingInboundRequests();
        $id = new RequestId(id: 7);
        $cancellation = $inboundRequests->claim($id);
        $logger = new ArrayLogger();

        new CancelledNotificationHandler($inboundRequests, $logger)->handle(self::notificationFor(7, 'user aborted'));

        self::assertNotNull($cancellation);
        self::assertTrue($cancellation->isRequested());

        $records = $logger->recordsMatching(LogLevel::DEBUG, 'Cancelled an in-flight request.');
        self::assertCount(1, $records);
        self::assertSame(['id' => 7, 'reason' => 'user aborted'], $records[0]['context']);
    }

    public function testLeavesOtherInFlightRequestsAlone(): void
    {
        $inboundRequests = new PendingInboundRequests();
        $target = $inboundRequests->claim(new RequestId(id: 7));
        $bystander = $inboundRequests->claim(new RequestId(id: 8));

        new CancelledNotificationHandler($inboundRequests)->handle(self::notificationFor(7));

        self::assertNotNull($target);
        self::assertNotNull($bystander);
        self::assertTrue($target->isRequested());
        self::assertFalse($bystander->isRequested());
    }

    public function testIgnoresAnIdThatIsNotInFlight(): void
    {
        // The spec has the receiver ignore an unknown id: the request may have completed before the
        // notification arrived.
        $logger = new ArrayLogger();

        new CancelledNotificationHandler(new PendingInboundRequests(), $logger)->handle(self::notificationFor(7));

        $records = $logger->recordsMatching(LogLevel::DEBUG, 'Ignoring cancellation for a request that is not in flight.');
        self::assertCount(1, $records);
        self::assertSame(['id' => 7], $records[0]['context']);
        self::assertSame(
            [],
            $logger->recordsMatching(LogLevel::DEBUG, 'Cancelled an in-flight request.'),
            'An unknown id must not also report a cancellation that never happened.',
        );
    }

    public function testDistinguishesAStringIdFromTheSameSpeltIntId(): void
    {
        $inboundRequests = new PendingInboundRequests();
        $intCancellation = $inboundRequests->claim(new RequestId(id: 7));
        $stringCancellation = $inboundRequests->claim(new RequestId(id: '7'));

        new CancelledNotificationHandler($inboundRequests)->handle(self::notificationFor('7'));

        self::assertNotNull($intCancellation);
        self::assertNotNull($stringCancellation);
        self::assertFalse($intCancellation->isRequested());
        self::assertTrue($stringCancellation->isRequested());
    }

    /**
     * @param int|non-empty-string  $requestId
     * @param null|non-empty-string $reason
     */
    private static function notificationFor(int|string $requestId, ?string $reason = null): CancelledNotification
    {
        return new CancelledNotification(
            params: new CancelledNotificationParams(requestId: new RequestId(id: $requestId), reason: $reason),
        );
    }
}
