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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListResourcesResult::class)]
#[CoversClass(PaginatedResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListResourcesResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $result = new ListResourcesResult([new Resource('r', 'file:///x')]);

        self::assertCount(1, $result->resources);
        self::assertNull($result->nextCursor);
        self::assertNull($result->meta);
    }

    public function testConstructionAcceptsEmptyResourcesList(): void
    {
        $result = new ListResourcesResult([]);

        self::assertSame([], $result->resources);
    }

    public function testToArrayEmitsResources(): void
    {
        $result = new ListResourcesResult([
            new Resource('a', 'file:///a'),
            new Resource('b', 'file:///b'),
        ]);

        self::assertSame(
            [
                'resources' => [
                    ['name' => 'a', 'uri' => 'file:///a'],
                    ['name' => 'b', 'uri' => 'file:///b'],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayIncludesNextCursor(): void
    {
        $result = new ListResourcesResult(
            [new Resource('a', 'file:///a')],
            new Cursor('cur-1'),
        );

        self::assertSame(
            [
                'nextCursor' => 'cur-1',
                'resources' => [['name' => 'a', 'uri' => 'file:///a']],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayIncludesMeta(): void
    {
        $result = new ListResourcesResult(
            [new Resource('a', 'file:///a')],
            null,
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resources' => [['name' => 'a', 'uri' => 'file:///a']],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayWithMetaAndNextCursor(): void
    {
        $result = new ListResourcesResult(
            [new Resource('a', 'file:///a')],
            new Cursor('cur-1'),
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'nextCursor' => 'cur-1',
                'resources' => [['name' => 'a', 'uri' => 'file:///a']],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ListResourcesResult(
            [new Resource('a', 'file:///a')],
            new Cursor('cur-1'),
            new MetaObject(['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesEmptyResources(): void
    {
        $result = ListResourcesResult::fromArray(['resources' => []]);

        self::assertSame([], $result->resources);
        self::assertNull($result->nextCursor);
        self::assertNull($result->meta);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $result = ListResourcesResult::fromArray([
            'resources' => [
                ['name' => 'a', 'uri' => 'file:///a'],
                ['name' => 'b', 'uri' => 'file:///b'],
            ],
            'nextCursor' => 'cur-1',
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertCount(2, $result->resources);
        self::assertSame('a', $result->resources[0]->name);
        self::assertSame('file:///a', $result->resources[0]->uri);
        self::assertNotNull($result->nextCursor);
        self::assertSame('cur-1', $result->nextCursor->cursor);
        self::assertNotNull($result->meta);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListResourcesResult(
            [new Resource('a', 'file:///a', 'A')],
            new Cursor('cur-1'),
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = ListResourcesResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListResources(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ListResourcesResult resources must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new ListResourcesResult([5 => new Resource('a', 'file:///a')]);
    }

    public function testConstructorRejectsNonResourceElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ListResourcesResult([42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ListResourcesResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing resources' => [
            [],
            'ListResourcesResult wire data missing "resources".',
        ];

        yield 'resources not an array' => [
            ['resources' => 'oops'],
            'ListResourcesResult wire "resources" must be a list, string given.',
        ];

        yield 'resource entry not an object' => [
            ['resources' => ['oops']],
            'ListResourcesResult wire resource entry must be an object, string given.',
        ];

        yield 'resource entry list-keyed' => [
            ['resources' => [['x']]],
            'ListResourcesResult wire resource entry must be a string-keyed object.',
        ];

        yield 'nextCursor not a string' => [
            ['resources' => [], 'nextCursor' => 1],
            'ListResourcesResult wire "nextCursor" must be a string, int given.',
        ];

        yield '_meta not an object' => [
            ['resources' => [], '_meta' => 'oops'],
            'Result "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['resources' => [], '_meta' => ['x']],
            'Result "_meta" must be a string-keyed object.',
        ];
    }
}
