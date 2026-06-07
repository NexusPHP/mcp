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
use Nexus\Mcp\Core\Schema\Task\RelatedTaskMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RelatedTaskMetadata::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RelatedTaskMetadataTest extends TestCase
{
    public function testConstructionStoresTaskId(): void
    {
        $meta = new RelatedTaskMetadata('task-abc');

        self::assertSame('task-abc', $meta->taskId);
    }

    public function testConstructorRejectsEmptyTaskId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('related task metadata "taskId" must be a non-empty string.');

        new RelatedTaskMetadata('');
    }

    public function testToArrayEmitsTaskId(): void
    {
        $meta = new RelatedTaskMetadata('task-abc');

        self::assertSame(['taskId' => 'task-abc'], $meta->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $meta = new RelatedTaskMetadata('task-abc');

        self::assertSame($meta->toArray(), $meta->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new RelatedTaskMetadata('task-abc');

        $rebuilt = RelatedTaskMetadata::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsMissingTaskId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('related task metadata missing the required "taskId" key.');

        RelatedTaskMetadata::fromArray([]);
    }

    public function testFromArrayRejectsNonStringTaskId(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('related task metadata "taskId" must be a string, int given.');

        RelatedTaskMetadata::fromArray(['taskId' => 42]);
    }
}
