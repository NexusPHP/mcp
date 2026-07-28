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

namespace Nexus\Mcp\Tests\Core\Schema\MetaObject;

use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\PayloadMetaObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PayloadMetaObject::class)]
#[CoversClass(MetaObject::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PayloadMetaObjectTest extends TestCase
{
    public function testDefaultsToEmptyExtras(): void
    {
        $meta = new PayloadMetaObject();

        self::assertSame([], $meta->extras);
    }

    public function testCapturesExtras(): void
    {
        $meta = new PayloadMetaObject(extras: ['vendor' => 'x', 'trace-id' => 123]);

        self::assertSame(['vendor' => 'x', 'trace-id' => 123], $meta->extras);
    }

    public function testFromArrayPopulatesExtras(): void
    {
        $meta = PayloadMetaObject::fromArray(['foo' => 1, 'bar' => ['nested' => true]]);

        self::assertSame(['foo' => 1, 'bar' => ['nested' => true]], $meta->extras);
    }

    public function testToArrayEmitsExtrasVerbatim(): void
    {
        $meta = new PayloadMetaObject(extras: ['a' => 1]);

        self::assertSame(['a' => 1], $meta->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $meta = new PayloadMetaObject(extras: ['a' => 1]);

        self::assertSame($meta->toArray(), $meta->jsonSerialize());
    }

    public function testRoundTripPreservesExtras(): void
    {
        $original = new PayloadMetaObject(extras: ['key' => 'value', 'num' => 42]);

        self::assertSame($original->toArray(), PayloadMetaObject::fromArray($original->toArray())->toArray());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        $meta = new PayloadMetaObject();

        self::assertInstanceOf(\stdClass::class, $meta->jsonSerialize());
        self::assertSame('{}', json_encode($meta));
    }
}
