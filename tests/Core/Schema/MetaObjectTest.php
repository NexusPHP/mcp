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
use Nexus\Mcp\Core\Schema\MetaObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MetaObject::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MetaObjectTest extends TestCase
{
    public function testDefaultsToEmptyExtras(): void
    {
        $meta = new MetaObject();

        self::assertSame([], $meta->extras);
    }

    public function testCapturesExtras(): void
    {
        $meta = new MetaObject(['vendor' => 'x', 'trace-id' => 123]);

        self::assertSame(['vendor' => 'x', 'trace-id' => 123], $meta->extras);
    }

    public function testFromArrayPopulatesExtras(): void
    {
        $meta = MetaObject::fromArray(['foo' => 1, 'bar' => ['nested' => true]]);

        self::assertSame(['foo' => 1, 'bar' => ['nested' => true]], $meta->extras);
    }

    public function testToArrayEmitsExtrasVerbatim(): void
    {
        $meta = new MetaObject(['a' => 1]);

        self::assertSame(['a' => 1], $meta->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $meta = new MetaObject(['a' => 1]);

        self::assertSame($meta->toArray(), $meta->jsonSerialize());
    }

    public function testRoundTripPreservesExtras(): void
    {
        $original = new MetaObject(['key' => 'value', 'num' => 42]);

        self::assertSame($original->toArray(), MetaObject::fromArray($original->toArray())->toArray());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        $meta = new MetaObject();

        self::assertInstanceOf(\stdClass::class, $meta->jsonSerialize());
        self::assertSame('{}', json_encode($meta));
    }

    public function testParseFromWireReturnsNullWhenMetaAbsent(): void
    {
        self::assertNull(MetaObject::parseFromWire(['name' => 'x'], 'Result'));
    }

    public function testParseFromWireReadsAndContextualizes(): void
    {
        $meta = MetaObject::parseFromWire(['_meta' => ['vendor' => 'x']], 'Result');

        self::assertNotNull($meta);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testParseFromWireRejectsNonObjectMeta(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Result "_meta" must be an object, string given.');

        MetaObject::parseFromWire(['_meta' => 'oops'], 'Result');
    }

    public function testParseFromWireRejectsListKeyedMeta(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Notification params "_meta" must be a string-keyed object.');

        MetaObject::parseFromWire(['_meta' => ['x']], 'Notification params');
    }
}
