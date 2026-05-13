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

namespace Nexus\Mcp\Tests\Core\Schema\Result;

use Nexus\Mcp\Core\Schema\Enum\TaskStatus;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\GetTaskResult;
use Nexus\Mcp\Core\Schema\Task\Task;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GetTaskResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class GetTaskResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $task = self::task();
        $result = new GetTaskResult($task);

        self::assertSame($task, $result->task);
        self::assertSame([], $result->meta->toArray());
    }

    public function testToArraySpreadsTaskFields(): void
    {
        $result = new GetTaskResult(self::task());

        self::assertSame(
            [
                'taskId' => 'task-abc',
                'status' => 'working',
                'createdAt' => '2026-05-10T12:00:00+00:00',
                'lastUpdatedAt' => '2026-05-10T12:00:30+00:00',
                'ttl' => null,
            ],
            $result->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $result = new GetTaskResult(self::task(), new MetaObject(['vendor' => 'x']));

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'taskId' => 'task-abc',
                'status' => 'working',
                'createdAt' => '2026-05-10T12:00:00+00:00',
                'lastUpdatedAt' => '2026-05-10T12:00:30+00:00',
                'ttl' => null,
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new GetTaskResult(self::task());

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new GetTaskResult(self::task(), new MetaObject(['vendor' => 'x']));

        $rebuilt = GetTaskResult::fromArray($original->toArray());

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
