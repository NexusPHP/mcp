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

namespace Nexus\Mcp\Tests\Extension\Tasks\Server\Store;

use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TaskRecord::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class TaskRecordTest extends TestCase
{
    public function testConstructionDefaultsTheOptionalSlotsToEmpty(): void
    {
        $record = new TaskRecord(
            taskId: 'task-1',
            toolName: 'slow_compute',
            status: TaskStatus::Working,
            createdAt: '2026-08-04T12:00:00+00:00',
            lastUpdatedAt: '2026-08-04T12:00:00+00:00',
            ttlMs: null,
            pollIntervalMs: 1_000,
        );

        self::assertSame('task-1', $record->taskId);
        self::assertSame('slow_compute', $record->toolName);
        self::assertSame(TaskStatus::Working, $record->status);
        self::assertNull($record->arguments);
        self::assertNull($record->result);
        self::assertNull($record->error);
        self::assertSame([], $record->pendingInputRequests);
        self::assertSame([], $record->inputResponses);
        self::assertNull($record->requestState);
        self::assertSame([], $record->issuedInputKeys);
        self::assertNull($record->statusMessage);
    }
}
