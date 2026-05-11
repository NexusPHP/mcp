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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\TaskStatus;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ListTasksResult;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
use Nexus\Mcp\Core\Schema\Task\Task;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListTasksResult::class)]
#[CoversClass(PaginatedResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListTasksResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $result = new ListTasksResult([]);

        self::assertSame([], $result->tasks);
        self::assertNull($result->nextCursor);
        self::assertNull($result->meta);
    }

    public function testConstructionAcceptsTasks(): void
    {
        $task = self::task();
        $result = new ListTasksResult([$task]);

        self::assertCount(1, $result->tasks);
        self::assertSame($task, $result->tasks[0]);
    }

    public function testToArrayMinimal(): void
    {
        $result = new ListTasksResult([]);

        self::assertSame(['tasks' => []], $result->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new ListTasksResult(
            [self::task()],
            new Cursor('cursor-1'),
            new Meta(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'nextCursor' => 'cursor-1',
                'tasks' => [
                    [
                        'taskId' => 'task-abc',
                        'status' => 'completed',
                        'createdAt' => '2026-05-10T12:00:00+00:00',
                        'lastUpdatedAt' => '2026-05-10T12:01:00+00:00',
                        'ttl' => null,
                    ],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ListTasksResult([self::task()]);

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListTasksResult(
            [self::task()],
            new Cursor('cursor-1'),
            new Meta(['vendor' => 'x']),
        );

        $rebuilt = ListTasksResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListTasks(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ListTasksResult tasks must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new ListTasksResult([5 => self::task()]);
    }

    public function testConstructorRejectsNonTaskEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ListTasksResult([42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ListTasksResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing tasks' => [
            [],
            'ListTasksResult wire data missing "tasks".',
        ];

        yield 'tasks not an array' => [
            ['tasks' => 'oops'],
            'ListTasksResult wire "tasks" must be a list, string given.',
        ];

        yield 'task entry not an object' => [
            ['tasks' => ['oops']],
            'ListTasksResult wire task entry must be an object, string given.',
        ];

        yield 'task entry list-keyed' => [
            ['tasks' => [['x']]],
            'ListTasksResult wire task entry must be a string-keyed object.',
        ];

        yield 'nextCursor not a string' => [
            ['tasks' => [], 'nextCursor' => 1],
            'ListTasksResult wire "nextCursor" must be a string, int given.',
        ];
    }

    private static function task(): Task
    {
        return new Task(
            'task-abc',
            TaskStatus::Completed,
            '2026-05-10T12:00:00+00:00',
            '2026-05-10T12:01:00+00:00',
            null,
        );
    }
}
