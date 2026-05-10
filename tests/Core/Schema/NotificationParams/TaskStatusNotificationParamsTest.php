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

namespace Nexus\Mcp\Tests\Core\Schema\NotificationParams;

use Nexus\Mcp\Core\Schema\Enum\TaskStatus;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\TaskStatusNotificationParams;
use Nexus\Mcp\Core\Schema\Task;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TaskStatusNotificationParams::class)]
#[CoversClass(NotificationParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TaskStatusNotificationParamsTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $task = self::task();
        $params = new TaskStatusNotificationParams($task);

        self::assertSame($task, $params->task);
        self::assertNull($params->meta);
    }

    public function testToArraySpreadsTaskFields(): void
    {
        $params = new TaskStatusNotificationParams(self::task());

        self::assertSame(
            [
                'taskId' => 'task-abc',
                'status' => 'working',
                'createdAt' => '2026-05-10T12:00:00+00:00',
                'lastUpdatedAt' => '2026-05-10T12:00:30+00:00',
                'ttl' => null,
            ],
            $params->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $params = new TaskStatusNotificationParams(self::task(), new Meta(['vendor' => 'x']));

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'taskId' => 'task-abc',
                'status' => 'working',
                'createdAt' => '2026-05-10T12:00:00+00:00',
                'lastUpdatedAt' => '2026-05-10T12:00:30+00:00',
                'ttl' => null,
            ],
            $params->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new TaskStatusNotificationParams(self::task());

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new TaskStatusNotificationParams(self::task(), new Meta(['vendor' => 'x']));

        $rebuilt = TaskStatusNotificationParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
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
