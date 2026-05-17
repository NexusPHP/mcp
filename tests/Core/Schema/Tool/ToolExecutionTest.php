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

namespace Nexus\Mcp\Tests\Core\Schema\Tool;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Enum\TaskSupport;
use Nexus\Mcp\Core\Schema\Tool\ToolExecution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolExecution::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ToolExecutionTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $execution = new ToolExecution();

        self::assertNull($execution->taskSupport);
    }

    public function testConstructionWithTaskSupport(): void
    {
        $execution = new ToolExecution(TaskSupport::Required);

        self::assertSame(TaskSupport::Required, $execution->taskSupport);
    }

    public function testToArrayEmitsTaskSupportValue(): void
    {
        $execution = new ToolExecution(TaskSupport::Optional);

        self::assertSame(['taskSupport' => 'optional'], $execution->toArray());
    }

    public function testToArrayOmitsAbsentTaskSupport(): void
    {
        self::assertSame([], new ToolExecution()->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $execution = new ToolExecution(TaskSupport::Forbidden);

        self::assertSame($execution->toArray(), $execution->jsonSerialize());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        $execution = new ToolExecution();

        self::assertInstanceOf(\stdClass::class, $execution->jsonSerialize());
        self::assertSame('{}', json_encode($execution));
    }

    public function testFromArrayDispatchesTaskSupport(): void
    {
        $execution = ToolExecution::fromArray(['taskSupport' => 'required']);

        self::assertSame(TaskSupport::Required, $execution->taskSupport);
    }

    public function testFromArrayWithoutTaskSupport(): void
    {
        $execution = ToolExecution::fromArray([]);

        self::assertNull($execution->taskSupport);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ToolExecution(TaskSupport::Optional);

        $rebuilt = ToolExecution::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsNonStringTaskSupport(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ToolExecution "taskSupport" must be one of [\'forbidden\', \'optional\', \'required\'], 1 given.');

        ToolExecution::fromArray(['taskSupport' => 1]);
    }

    public function testFromArrayRejectsUnknownTaskSupport(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ToolExecution "taskSupport" must be one of [\'forbidden\', \'optional\', \'required\'], \'unknown\' given.');

        ToolExecution::fromArray(['taskSupport' => 'unknown']);
    }
}
