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

namespace Nexus\Mcp\Tests\Core\Schema\RequestParams;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PaginatedRequestParams::class)]
#[CoversClass(RequestParams::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PaginatedRequestParamsTest extends TestCase
{
    public function testConstructionDefaultsCursorToNull(): void
    {
        $params = new PaginatedRequestParams(meta: RequestMetaObjectFactory::create());

        self::assertNull($params->cursor);
    }

    public function testToArrayMinimal(): void
    {
        $params = new PaginatedRequestParams(meta: RequestMetaObjectFactory::create());

        self::assertSame(['_meta' => RequestMetaObjectFactory::shape()], $params->toArray());
    }

    public function testToArrayWithCursor(): void
    {
        $params = new PaginatedRequestParams(meta: RequestMetaObjectFactory::create(), cursor: new Cursor(cursor: 'cur-1'));

        self::assertSame(
            ['_meta' => RequestMetaObjectFactory::shape(), 'cursor' => 'cur-1'],
            $params->toArray(),
        );
    }

    public function testToArrayKeyOrder(): void
    {
        $params = new PaginatedRequestParams(meta: RequestMetaObjectFactory::create(), cursor: new Cursor(cursor: 'cur-1'));

        self::assertSame(
            ['_meta', 'cursor'],
            array_keys($params->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new PaginatedRequestParams(meta: RequestMetaObjectFactory::create(null, ['k' => 'v']), cursor: new Cursor(cursor: 'cur-1'));

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $params = PaginatedRequestParams::fromArray(['_meta' => RequestMetaObjectFactory::shape()]);

        self::assertNull($params->cursor);
    }

    public function testFromArrayParsesCursor(): void
    {
        $params = PaginatedRequestParams::fromArray([
            'cursor' => 'cur-1',
            '_meta' => RequestMetaObjectFactory::shape(),
        ]);

        self::assertNotNull($params->cursor);
        self::assertSame('cur-1', $params->cursor->cursor);
    }

    public function testFromArrayParsesMeta(): void
    {
        $params = PaginatedRequestParams::fromArray(['_meta' => RequestMetaObjectFactory::shape(null, ['vendor' => 'x'])]);
        self::assertSame(['vendor' => 'x'], $params->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new PaginatedRequestParams(meta: RequestMetaObjectFactory::create(null, ['vendor' => 'x']), cursor: new Cursor(cursor: 'cur-1'));

        $rebuilt = PaginatedRequestParams::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        PaginatedRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'cursor not a string' => [
            ['cursor' => 1],
            '"params.cursor" must be a non-empty string, int given.',
        ];

        yield 'missing _meta' => [
            [],
            '"params" is missing the required "_meta" key.',
        ];

        yield '_meta not an object' => [
            ['_meta' => 'oops'],
            '"params._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['_meta' => ['x']],
            '"params._meta" must be a string-keyed object.',
        ];
    }
}
