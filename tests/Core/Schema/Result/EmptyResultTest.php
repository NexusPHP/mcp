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

use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\ResultMetaObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EmptyResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class EmptyResultTest extends TestCase
{
    public function testDefaultsToNullMeta(): void
    {
        self::assertSame([], new EmptyResult()->meta->toArray());
    }

    public function testToArrayCarriesResultTypeWhenNoMeta(): void
    {
        self::assertSame(['resultType' => 'complete'], new EmptyResult()->toArray());
    }

    public function testToArrayEmitsMeta(): void
    {
        $result = new EmptyResult(meta: new ResultMetaObject(extras: ['vendor' => 'x']));

        self::assertSame(['_meta' => ['vendor' => 'x'], 'resultType' => 'complete'], $result->toArray());
    }

    public function testRebuildingWithNewMetaKeepsEveryOtherField(): void
    {
        $result = new EmptyResult(meta: new ResultMetaObject(extras: ['vendor' => 'x']));

        $rebuilt = $result->rebuildWithMeta(new ResultMetaObject(extras: ['replaced' => true]));

        self::assertSame(['_meta' => ['replaced' => true]] + $result->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayWithoutMetaYieldsNullMeta(): void
    {
        self::assertSame([], EmptyResult::fromArray([])->meta->toArray());
    }

    public function testFromArrayParsesMeta(): void
    {
        $result = EmptyResult::fromArray(['_meta' => ['a' => 1]]);
        self::assertSame(['a' => 1], $result->meta->extras);
    }

    public function testFromArrayRejectsNonObjectMeta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result._meta" must be an object, bool given.');

        EmptyResult::fromArray(['_meta' => true]);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new EmptyResult(meta: new ResultMetaObject(extras: ['k' => 'v']));

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testRoundTripPreservesMeta(): void
    {
        $original = new EmptyResult(meta: new ResultMetaObject(extras: ['vendor' => 'x']));

        self::assertSame($original->toArray(), EmptyResult::fromArray($original->toArray())->toArray());
    }
}
