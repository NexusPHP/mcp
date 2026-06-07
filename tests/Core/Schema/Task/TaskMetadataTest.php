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

namespace Nexus\Mcp\Tests\Core\Schema\Task;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Task\TaskMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TaskMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TaskMetadataTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $task = new TaskMetadata();

        self::assertNull($task->ttl);
    }

    public function testConstructionWithTtl(): void
    {
        $task = new TaskMetadata(60000);

        self::assertSame(60000, $task->ttl);
    }

    public function testToArrayEmitsTtl(): void
    {
        $task = new TaskMetadata(60000);

        self::assertSame(['ttl' => 60000], $task->toArray());
    }

    public function testToArrayOmitsAbsentTtl(): void
    {
        self::assertSame([], new TaskMetadata()->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $task = new TaskMetadata(60000);

        self::assertSame($task->toArray(), $task->jsonSerialize());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        $task = new TaskMetadata();

        self::assertInstanceOf(\stdClass::class, $task->jsonSerialize());
        self::assertSame('{}', json_encode($task));
    }

    public function testFromArrayParsesTtl(): void
    {
        $task = TaskMetadata::fromArray(['ttl' => 60000]);

        self::assertSame(60000, $task->ttl);
    }

    public function testFromArrayWithoutTtl(): void
    {
        $task = TaskMetadata::fromArray([]);

        self::assertNull($task->ttl);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new TaskMetadata(60000);

        $rebuilt = TaskMetadata::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsNonIntTtl(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('task metadata "ttl" must be an int or null, string given.');

        TaskMetadata::fromArray(['ttl' => 'oops']);
    }
}
