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

use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
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

    public function testToArrayIsEmptyWhenNoMeta(): void
    {
        self::assertSame([], new EmptyResult()->toArray());
    }

    public function testToArrayEmitsMeta(): void
    {
        $result = new EmptyResult(new MetaObject(['vendor' => 'x']));

        self::assertSame(['_meta' => ['vendor' => 'x']], $result->toArray());
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
        $this->expectExceptionMessage('"result._meta" must be an object, bool given.');

        EmptyResult::fromArray(['_meta' => true]);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new EmptyResult(new MetaObject(['k' => 'v']));

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testRoundTripPreservesMeta(): void
    {
        $original = new EmptyResult(new MetaObject(['vendor' => 'x']));

        self::assertSame($original->toArray(), EmptyResult::fromArray($original->toArray())->toArray());
    }
}
