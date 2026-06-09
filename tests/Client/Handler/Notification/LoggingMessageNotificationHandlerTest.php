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

namespace Nexus\Mcp\Tests\Client\Handler\Notification;

use Nexus\Mcp\Client\Handler\Notification\LoggingMessageNotificationHandler;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\LoggingMessageNotificationParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(LoggingMessageNotificationHandler::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class LoggingMessageNotificationHandlerTest extends TestCase
{
    public function testInvokesTheClosureWithTheNotification(): void
    {
        $received = null;
        $handler = new LoggingMessageNotificationHandler(
            static function (LoggingMessageNotification $notification) use (&$received): void {
                $received = $notification;
            },
        );

        $notification = new LoggingMessageNotification(
            params: new LoggingMessageNotificationParams(level: LoggingLevel::Info, data: 'hello', logger: 'demo'),
        );
        $handler->handle($notification);

        self::assertSame($notification, $received);
    }
}
