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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
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
    public function testConstructionDefaultsCursorAndMetaToNull(): void
    {
        $params = new PaginatedRequestParams();

        self::assertNull($params->cursor);
        self::assertNull($params->meta);
    }

    public function testToArrayMinimal(): void
    {
        $params = new PaginatedRequestParams();

        self::assertSame([], $params->toArray());
    }

    public function testToArrayWithCursor(): void
    {
        $params = new PaginatedRequestParams(new Cursor('cur-1'));

        self::assertSame(['cursor' => 'cur-1'], $params->toArray());
    }

    public function testToArrayWithMeta(): void
    {
        $params = new PaginatedRequestParams(null, new RequestMetaObject(null, ['vendor' => 'x']));

        self::assertSame(['_meta' => ['vendor' => 'x']], $params->toArray());
    }

    public function testToArrayKeyOrder(): void
    {
        $params = new PaginatedRequestParams(
            new Cursor('cur-1'),
            new RequestMetaObject(null, ['k' => 'v']),
        );

        self::assertSame(
            ['_meta', 'cursor'],
            array_keys($params->toArray()),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $params = new PaginatedRequestParams(
            new Cursor('cur-1'),
            new RequestMetaObject(null, ['k' => 'v']),
        );

        self::assertSame($params->toArray(), $params->jsonSerialize());
    }

    public function testFromArrayMinimal(): void
    {
        $params = PaginatedRequestParams::fromArray([]);

        self::assertNull($params->cursor);
        self::assertNull($params->meta);
    }

    public function testFromArrayParsesCursor(): void
    {
        $params = PaginatedRequestParams::fromArray(['cursor' => 'cur-1']);

        self::assertNotNull($params->cursor);
        self::assertSame('cur-1', $params->cursor->cursor);
    }

    public function testFromArrayParsesMeta(): void
    {
        $params = PaginatedRequestParams::fromArray(['_meta' => ['vendor' => 'x']]);

        self::assertNotNull($params->meta);
        self::assertSame(['vendor' => 'x'], $params->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new PaginatedRequestParams(
            new Cursor('cur-1'),
            new RequestMetaObject(null, ['vendor' => 'x']),
        );

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
        $this->expectExceptionMessage($expectedMessage);

        PaginatedRequestParams::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'cursor not a string' => [
            ['cursor' => 1],
            'PaginatedRequestParams "cursor" must be a string, int given.',
        ];

        yield '_meta not an object' => [
            ['_meta' => 'oops'],
            'Request params "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['_meta' => ['x']],
            'Request params "_meta" must be a string-keyed object.',
        ];
    }
}
