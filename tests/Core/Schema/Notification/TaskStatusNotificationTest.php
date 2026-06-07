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

namespace Nexus\Mcp\Tests\Core\Schema\Notification;

use Nexus\Mcp\Core\Schema\Enum\TaskStatus;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\Notification\TaskStatusNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\TaskStatusNotificationParams;
use Nexus\Mcp\Core\Schema\Task\Task;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TaskStatusNotification::class)]
#[CoversClass(JsonRpcNotification::class)]
#[CoversClass(Notification::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TaskStatusNotificationTest extends TestCase
{
    public function testMethodIsNotificationsTasksStatus(): void
    {
        self::assertSame('notifications/tasks/status', TaskStatusNotification::getMethod());
    }

    public function testToArray(): void
    {
        $notification = new TaskStatusNotification(new TaskStatusNotificationParams(self::task()));

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/tasks/status',
                'params' => [
                    'taskId' => 'task-abc',
                    'status' => 'working',
                    'createdAt' => '2026-05-10T12:00:00+00:00',
                    'lastUpdatedAt' => '2026-05-10T12:00:30+00:00',
                    'ttl' => null,
                ],
            ],
            $notification->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $notification = new TaskStatusNotification(new TaskStatusNotificationParams(self::task()));

        self::assertSame($notification->toArray(), $notification->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new TaskStatusNotification(new TaskStatusNotificationParams(self::task()));

        $rebuilt = TaskStatusNotification::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsMissingParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^missing the required "params" key\.$/');

        TaskStatusNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tasks/status',
        ]);
    }

    public function testFromArrayRejectsNonObjectParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params" must be an object, string given.');

        TaskStatusNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tasks/status',
            'params' => 'bad',
        ]);
    }

    public function testFromArrayRejectsListKeyedParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"params" must be a string-keyed object.');

        TaskStatusNotification::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tasks/status',
            'params' => ['a', 'b'],
        ]);
    }

    private static function task(): Task
    {
        return new Task(
            'task-abc',
            TaskStatus::Working,
            '2026-05-10T12:00:00+00:00',
            '2026-05-10T12:00:30+00:00',
            null,
        );
    }
}
