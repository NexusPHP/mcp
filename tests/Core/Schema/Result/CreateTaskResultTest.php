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
use Nexus\Mcp\Core\Schema\Enum\TaskStatus;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Core\Schema\Task\Task;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CreateTaskResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CreateTaskResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $task = self::task();
        $result = new CreateTaskResult($task);

        self::assertSame($task, $result->task);
        self::assertSame([], $result->meta->toArray());
    }

    public function testToArrayWrapsTask(): void
    {
        $result = new CreateTaskResult(self::task());

        self::assertSame(
            [
                'task' => [
                    'taskId' => 'task-abc',
                    'status' => 'working',
                    'createdAt' => '2026-05-10T12:00:00+00:00',
                    'lastUpdatedAt' => '2026-05-10T12:00:00+00:00',
                    'ttl' => null,
                ],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $result = new CreateTaskResult(self::task(), new MetaObject(['vendor' => 'x']));

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'task' => [
                    'taskId' => 'task-abc',
                    'status' => 'working',
                    'createdAt' => '2026-05-10T12:00:00+00:00',
                    'lastUpdatedAt' => '2026-05-10T12:00:00+00:00',
                    'ttl' => null,
                ],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new CreateTaskResult(self::task());

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new CreateTaskResult(self::task(), new MetaObject(['vendor' => 'x']));

        $rebuilt = CreateTaskResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        CreateTaskResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing task' => [
            [],
            '"result" missing the required "task" key.',
        ];

        yield 'task not an object' => [
            ['task' => 'oops'],
            '"result.task" must be an object, string given.',
        ];

        yield 'task list-keyed' => [
            ['task' => ['x']],
            '"result.task" must be a string-keyed object.',
        ];
    }

    private static function task(): Task
    {
        return new Task(
            'task-abc',
            TaskStatus::Working,
            '2026-05-10T12:00:00+00:00',
            '2026-05-10T12:00:00+00:00',
            null,
        );
    }
}
