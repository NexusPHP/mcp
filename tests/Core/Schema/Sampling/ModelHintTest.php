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

namespace Nexus\Mcp\Tests\Core\Schema\Sampling;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Sampling\ModelHint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ModelHint::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ModelHintTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $hint = new ModelHint();

        self::assertNull($hint->name);
    }

    public function testConstructionWithName(): void
    {
        $hint = new ModelHint('claude-3-5-sonnet');

        self::assertSame('claude-3-5-sonnet', $hint->name);
    }

    public function testConstructorRejectsEmptyName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"hints.name" must be a non-empty string or null.');

        new ModelHint('');
    }

    public function testToArrayEmitsName(): void
    {
        self::assertSame(['name' => 'sonnet'], new ModelHint('sonnet')->toArray());
    }

    public function testToArrayOmitsAbsentName(): void
    {
        self::assertSame([], new ModelHint()->toArray());
    }

    public function testJsonSerializeMatchesToArrayWhenNonEmpty(): void
    {
        $hint = new ModelHint('sonnet');

        self::assertSame($hint->toArray(), $hint->jsonSerialize());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        self::assertInstanceOf(\stdClass::class, new ModelHint()->jsonSerialize());
        self::assertSame('{}', json_encode(new ModelHint()));
    }

    public function testFromArrayParsesName(): void
    {
        $hint = ModelHint::fromArray(['name' => 'sonnet']);

        self::assertSame('sonnet', $hint->name);
    }

    public function testFromArrayWithoutName(): void
    {
        $hint = ModelHint::fromArray([]);

        self::assertNull($hint->name);
    }

    public function testFromArrayRejectsNonStringName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"hints.name" must be a string or null, int given.');

        ModelHint::fromArray(['name' => 42]);
    }
}
