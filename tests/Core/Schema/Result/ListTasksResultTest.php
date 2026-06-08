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
use Nexus\Mcp\Core\Schema\MetaObject;
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
        self::assertSame([], $result->meta->toArray());
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

        self::assertSame(['resultType' => 'complete', 'tasks' => []], $result->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new ListTasksResult(
            [self::task()],
            new Cursor('cursor-1'),
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
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
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = ListTasksResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListTasks(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.tasks" must be a list, non-list array given.');

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
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ListTasksResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing tasks' => [
            [],
            '"result" missing the required "tasks" key.',
        ];

        yield 'tasks not an array' => [
            ['tasks' => 'oops'],
            '"result.tasks" must be a list, string given.',
        ];

        yield 'task entry not an object' => [
            ['tasks' => ['oops']],
            'each "result.task" must be an object, string given.',
        ];

        yield 'task entry list-keyed' => [
            ['tasks' => [['x']]],
            'each "result.task" must be a string-keyed object.',
        ];

        yield 'nextCursor not a string' => [
            ['tasks' => [], 'nextCursor' => 1],
            '"result.nextCursor" must be a string, int given.',
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
