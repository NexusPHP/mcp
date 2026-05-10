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

namespace Nexus\Mcp\Tests\Core\Schema;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Enum\TaskStatus;
use Nexus\Mcp\Core\Schema\Task;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Task::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TaskTest extends TestCase
{
    public function testConstructionRequiredFields(): void
    {
        $task = new Task('task-abc', TaskStatus::Working, '2026-05-10T12:00:00+00:00', '2026-05-10T12:00:30+00:00', null);

        self::assertSame('task-abc', $task->taskId);
        self::assertSame(TaskStatus::Working, $task->status);
        self::assertSame('2026-05-10T12:00:00+00:00', $task->createdAt->format(\DATE_RFC3339));
        self::assertSame('2026-05-10T12:00:30+00:00', $task->lastUpdatedAt->format(\DATE_RFC3339));
        self::assertNull($task->ttl);
        self::assertNull($task->statusMessage);
        self::assertNull($task->pollInterval);
    }

    public function testConstructionAllFields(): void
    {
        $task = new Task(
            'task-abc',
            TaskStatus::Completed,
            '2026-05-10T12:00:00+00:00',
            '2026-05-10T12:01:00+00:00',
            60000,
            'All steps finished.',
            500,
        );

        self::assertSame(60000, $task->ttl);
        self::assertSame('All steps finished.', $task->statusMessage);
        self::assertSame(500, $task->pollInterval);
    }

    public function testConstructorRejectsEmptyTaskId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Task taskId must be a non-empty string.');

        new Task('', TaskStatus::Working, '2026-05-10T12:00:00+00:00', '2026-05-10T12:00:00+00:00', null);
    }

    public function testConstructorRejectsEmptyStatusMessage(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Task statusMessage must be a non-empty string or null.');

        new Task('task-abc', TaskStatus::Working, '2026-05-10T12:00:00+00:00', '2026-05-10T12:00:00+00:00', null, '');
    }

    public function testConstructorAcceptsZeroTtl(): void
    {
        $task = new Task('task-abc', TaskStatus::Working, '2026-05-10T12:00:00+00:00', '2026-05-10T12:00:00+00:00', 0);

        self::assertSame(0, $task->ttl);
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ttl must be a non-negative integer or null.');

        new Task('task-abc', TaskStatus::Working, '2026-05-10T12:00:00+00:00', '2026-05-10T12:00:00+00:00', -1);
    }

    public function testConstructorAcceptsZeroPollInterval(): void
    {
        $task = new Task('task-abc', TaskStatus::Working, '2026-05-10T12:00:00+00:00', '2026-05-10T12:00:00+00:00', null, null, 0);

        self::assertSame(0, $task->pollInterval);
    }

    public function testConstructorRejectsNegativePollInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task pollInterval must be a non-negative integer or null.');

        new Task('task-abc', TaskStatus::Working, '2026-05-10T12:00:00+00:00', '2026-05-10T12:00:00+00:00', null, null, -1);
    }

    /**
     * @param non-empty-string $field
     */
    #[DataProvider('provideConstructorRejectsInvalidTimestampCases')]
    public function testConstructorRejectsInvalidTimestamp(string $field, string $value, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        if ('createdAt' === $field) {
            new Task('task-abc', TaskStatus::Working, $value, '2026-05-10T12:00:00+00:00', null);
        } else {
            new Task('task-abc', TaskStatus::Working, '2026-05-10T12:00:00+00:00', $value, null);
        }
    }

    /**
     * @return iterable<string, array{non-empty-string, string, string}>
     */
    public static function provideConstructorRejectsInvalidTimestampCases(): iterable
    {
        yield 'createdAt with NUL byte' => ['createdAt', "2026-05-10T12:00:00\0+00:00", 'Task createdAt must not contain NULL bytes.'];

        yield 'createdAt invalid format' => ['createdAt', 'not-a-date', 'Task createdAt must be a valid ISO 8601 datetime.'];

        yield 'createdAt overflow triggers warnings' => ['createdAt', '2026-13-45T25:99:99+00:00', 'The parsed date was invalid.'];

        yield 'lastUpdatedAt with NUL byte' => ['lastUpdatedAt', "2026-05-10T12:00:00\0+00:00", 'Task lastUpdatedAt must not contain NULL bytes.'];

        yield 'lastUpdatedAt invalid format' => ['lastUpdatedAt', 'not-a-date', 'Task lastUpdatedAt must be a valid ISO 8601 datetime.'];

        yield 'lastUpdatedAt overflow triggers warnings' => ['lastUpdatedAt', '2026-13-45T25:99:99+00:00', 'The parsed date was invalid.'];
    }

    public function testConstructorAcceptsRfc3339ExtendedTimestamp(): void
    {
        $task = new Task(
            'task-abc',
            TaskStatus::Working,
            '2026-05-10T12:00:00.123+00:00',
            '2026-05-10T12:00:30.456+00:00',
            null,
        );

        self::assertSame('2026-05-10T12:00:00+00:00', $task->createdAt->format(\DATE_RFC3339));
        self::assertSame('2026-05-10T12:00:30+00:00', $task->lastUpdatedAt->format(\DATE_RFC3339));
    }

    public function testToArrayEmitsRequiredFieldsAndNullTtl(): void
    {
        $task = new Task('task-abc', TaskStatus::Working, '2026-05-10T12:00:00+00:00', '2026-05-10T12:00:30+00:00', null);

        self::assertSame(
            [
                'taskId' => 'task-abc',
                'status' => 'working',
                'createdAt' => '2026-05-10T12:00:00+00:00',
                'lastUpdatedAt' => '2026-05-10T12:00:30+00:00',
                'ttl' => null,
            ],
            $task->toArray(),
        );
    }

    public function testToArrayEmitsAllFields(): void
    {
        $task = new Task(
            'task-abc',
            TaskStatus::Completed,
            '2026-05-10T12:00:00+00:00',
            '2026-05-10T12:01:00+00:00',
            60000,
            'All steps finished.',
            500,
        );

        self::assertSame(
            [
                'taskId' => 'task-abc',
                'status' => 'completed',
                'createdAt' => '2026-05-10T12:00:00+00:00',
                'lastUpdatedAt' => '2026-05-10T12:01:00+00:00',
                'ttl' => 60000,
                'statusMessage' => 'All steps finished.',
                'pollInterval' => 500,
            ],
            $task->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $task = new Task('task-abc', TaskStatus::Working, '2026-05-10T12:00:00+00:00', '2026-05-10T12:00:30+00:00', null);

        self::assertSame($task->toArray(), $task->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new Task(
            'task-abc',
            TaskStatus::Completed,
            '2026-05-10T12:00:00+00:00',
            '2026-05-10T12:01:00+00:00',
            60000,
            'All steps finished.',
            500,
        );

        $rebuilt = Task::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        Task::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        $valid = [
            'taskId' => 'task-abc',
            'status' => 'working',
            'createdAt' => '2026-05-10T12:00:00+00:00',
            'lastUpdatedAt' => '2026-05-10T12:00:30+00:00',
            'ttl' => null,
        ];

        yield 'missing taskId' => [['status' => 'working'], 'Task wire data missing "taskId".'];

        yield 'taskId not a string' => [[...$valid, 'taskId' => 42], 'Task wire "taskId" must be a string, int given.'];

        yield 'missing status' => [['taskId' => 'task-abc'], 'Task wire data missing "status".'];

        yield 'status not a string' => [[...$valid, 'status' => 42], 'Task wire "status" must be a string, int given.'];

        yield 'missing createdAt' => [['taskId' => 'task-abc', 'status' => 'working'], 'Task wire data missing "createdAt".'];

        yield 'createdAt not a string' => [[...$valid, 'createdAt' => 42], 'Task wire "createdAt" must be a string, int given.'];

        yield 'missing lastUpdatedAt' => [
            ['taskId' => 'task-abc', 'status' => 'working', 'createdAt' => '2026-05-10T12:00:00+00:00'],
            'Task wire data missing "lastUpdatedAt".',
        ];

        yield 'lastUpdatedAt not a string' => [[...$valid, 'lastUpdatedAt' => 42], 'Task wire "lastUpdatedAt" must be a string, int given.'];

        yield 'missing ttl' => [
            ['taskId' => 'task-abc', 'status' => 'working', 'createdAt' => '2026-05-10T12:00:00+00:00', 'lastUpdatedAt' => '2026-05-10T12:00:30+00:00'],
            'Task wire data missing "ttl".',
        ];

        yield 'ttl wrong type' => [[...$valid, 'ttl' => 'oops'], 'Task wire "ttl" must be an int or null, string given.'];

        yield 'statusMessage wrong type' => [[...$valid, 'statusMessage' => 42], 'Task wire "statusMessage" must be a string or null, int given.'];

        yield 'pollInterval wrong type' => [[...$valid, 'pollInterval' => 'oops'], 'Task wire "pollInterval" must be an int or null, string given.'];
    }

    public function testFromArrayParsesAllOptionalFields(): void
    {
        $task = Task::fromArray([
            'taskId' => 'task-abc',
            'status' => 'completed',
            'createdAt' => '2026-05-10T12:00:00+00:00',
            'lastUpdatedAt' => '2026-05-10T12:01:00+00:00',
            'ttl' => 60000,
            'statusMessage' => 'Done.',
            'pollInterval' => 500,
        ]);

        self::assertSame(60000, $task->ttl);
        self::assertSame('Done.', $task->statusMessage);
        self::assertSame(500, $task->pollInterval);
        self::assertSame(TaskStatus::Completed, $task->status);
    }
}
