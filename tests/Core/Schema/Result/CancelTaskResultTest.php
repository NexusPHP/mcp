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
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CancelTaskResult;
use Nexus\Mcp\Core\Schema\Task;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CancelTaskResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CancelTaskResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $task = self::task();
        $result = new CancelTaskResult($task);

        self::assertSame($task, $result->task);
        self::assertNull($result->meta);
    }

    public function testToArraySpreadsTaskFields(): void
    {
        $result = new CancelTaskResult(self::task());

        self::assertSame(
            [
                'taskId' => 'task-abc',
                'status' => 'cancelled',
                'createdAt' => '2026-05-10T12:00:00+00:00',
                'lastUpdatedAt' => '2026-05-10T12:00:45+00:00',
                'ttl' => null,
            ],
            $result->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $result = new CancelTaskResult(self::task(), new Meta(['vendor' => 'x']));

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'taskId' => 'task-abc',
                'status' => 'cancelled',
                'createdAt' => '2026-05-10T12:00:00+00:00',
                'lastUpdatedAt' => '2026-05-10T12:00:45+00:00',
                'ttl' => null,
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new CancelTaskResult(self::task());

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new CancelTaskResult(self::task(), new Meta(['vendor' => 'x']));

        $rebuilt = CancelTaskResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    private static function task(): Task
    {
        return new Task(
            'task-abc',
            TaskStatus::Cancelled,
            '2026-05-10T12:00:00+00:00',
            '2026-05-10T12:00:45+00:00',
            null,
        );
    }
}
