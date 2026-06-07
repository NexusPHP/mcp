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

use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequestMetaObject::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class RequestMetaObjectTest extends TestCase
{
    public function testDefaultsToNoProgressTokenAndNoExtras(): void
    {
        $meta = new RequestMetaObject();

        self::assertNull($meta->progressToken);
        self::assertSame([], $meta->extras);
    }

    public function testCapturesProgressTokenAndExtras(): void
    {
        $meta = new RequestMetaObject(new ProgressToken('tok-1'), ['vendor' => 'x']);

        self::assertInstanceOf(ProgressToken::class, $meta->progressToken);
        self::assertSame('tok-1', $meta->progressToken->token);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testToArrayEmitsProgressTokenAtExpectedKey(): void
    {
        $meta = new RequestMetaObject(new ProgressToken(42));

        self::assertSame(['progressToken' => 42], $meta->toArray());
    }

    public function testToArrayMergesExtrasAndProgressToken(): void
    {
        $meta = new RequestMetaObject(new ProgressToken('tok-1'), ['vendor' => 'x']);

        self::assertSame(['vendor' => 'x', 'progressToken' => 'tok-1'], $meta->toArray());
    }

    public function testToArrayOmitsProgressTokenWhenNull(): void
    {
        $meta = new RequestMetaObject(null, ['a' => 1]);

        self::assertSame(['a' => 1], $meta->toArray());
    }

    public function testFromArrayExtractsProgressTokenAndKeepsExtras(): void
    {
        $meta = RequestMetaObject::fromArray(['progressToken' => 'tok-1', 'vendor' => 'x']);

        self::assertNotNull($meta->progressToken);
        self::assertSame('tok-1', $meta->progressToken->token);
        self::assertSame(['vendor' => 'x'], $meta->extras);
    }

    public function testFromArrayWithoutProgressTokenLeavesItNull(): void
    {
        $meta = RequestMetaObject::fromArray(['a' => 1, 'b' => 2]);

        self::assertNull($meta->progressToken);
        self::assertSame(['a' => 1, 'b' => 2], $meta->extras);
    }

    public function testFromArrayRejectsNonIntOrStringProgressToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"_meta.progressToken" must be an int or string, array given.');

        RequestMetaObject::fromArray(['progressToken' => []]);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $meta = new RequestMetaObject(new ProgressToken('tok-1'), ['vendor' => 'x']);

        self::assertSame($meta->toArray(), $meta->jsonSerialize());
    }

    public function testRoundTripPreservesEverything(): void
    {
        $original = new RequestMetaObject(new ProgressToken(7), ['key' => 'value']);

        self::assertSame($original->toArray(), RequestMetaObject::fromArray($original->toArray())->toArray());
    }

    public function testJsonSerializeSubstitutesStdClassWhenEmpty(): void
    {
        $meta = new RequestMetaObject();

        self::assertInstanceOf(\stdClass::class, $meta->jsonSerialize());
        self::assertSame('{}', json_encode($meta));
    }
}
