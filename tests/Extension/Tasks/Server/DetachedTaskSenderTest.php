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

namespace Nexus\Mcp\Tests\Extension\Tasks\Server;

use Nexus\Mcp\Core\Exception\OutboundRequestsNotSupportedException;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Extension\Tasks\Server\DetachedTaskSender;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\TestClientRequest;
use Nexus\Mcp\Tests\Fixtures\Core\TestNotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

/**
 * @internal
 */
#[CoversClass(DetachedTaskSender::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class DetachedTaskSenderTest extends AbstractMcpTestCase
{
    public function testDropsNotificationsWithADebugLog(): void
    {
        $logger = new ArrayLogger();
        $sender = new DetachedTaskSender($logger, new RequestId(id: 7));

        $sender->sendNotification(new TestNotification(params: new EmptyNotificationParams()));

        $records = $logger->recordsMatching(LogLevel::DEBUG, 'Dropping a {method} notification from a detached task fiber.');
        self::assertCount(1, $records);
        self::assertSame(['method' => TestNotification::getMethod()], $records[0]['context']);
    }

    public function testRefusesRequests(): void
    {
        $sender = new DetachedTaskSender(new ArrayLogger(), new RequestId(id: 7));

        $this->expectException(OutboundRequestsNotSupportedException::class);
        $this->expectExceptionMessageIs('Outbound server-to-client requests are not implemented yet.');

        $sender->sendRequest(TestClientRequest::fromArray(['id' => 7, 'params' => ['_meta' => RequestMetaObjectFactory::shape()]]));
    }
}
