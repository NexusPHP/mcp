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
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ListResourcesResult::class)]
#[CoversClass(PaginatedResult::class)]
#[CoversClass(CacheableResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListResourcesResultTest extends AbstractMcpTestCase
{
    public function testConstructionDefaults(): void
    {
        $result = new ListResourcesResult(resources: [new Resource(name: 'r', uri: 'file:///x')], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertCount(1, $result->resources);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertNull($result->nextCursor);
        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructionAcceptsEmptyResourcesList(): void
    {
        $result = new ListResourcesResult(resources: [], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame([], $result->resources);
    }

    public function testToArrayEmitsResources(): void
    {
        $result = new ListResourcesResult(resources: [
            new Resource(name: 'a', uri: 'file:///a'),
            new Resource(name: 'b', uri: 'file:///b'),
        ], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame(
            [
                'resultType' => 'complete',
                'resources' => [
                    ['name' => 'a', 'uri' => 'file:///a'],
                    ['name' => 'b', 'uri' => 'file:///b'],
                ],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
            $result->toArray(),
        );
    }

    public function testToArrayIncludesNextCursor(): void
    {
        $result = new ListResourcesResult(
            resources: [new Resource(name: 'a', uri: 'file:///a')],
            ttlMs: 0,
            cacheScope: CacheScope::Private,
            nextCursor: new Cursor(cursor: 'cur-1'),
        );

        self::assertSame(
            [
                'resultType' => 'complete',
                'resources' => [['name' => 'a', 'uri' => 'file:///a']],
                'nextCursor' => 'cur-1',
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
            $result->toArray(),
        );
    }

    public function testToArrayIncludesMeta(): void
    {
        $result = new ListResourcesResult(
            resources: [new Resource(name: 'a', uri: 'file:///a')],
            ttlMs: 0,
            cacheScope: CacheScope::Private,
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'resources' => [['name' => 'a', 'uri' => 'file:///a']],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
            $result->toArray(),
        );
    }

    public function testRebuildingWithNewMetaKeepsEveryOtherField(): void
    {
        $result = new ListResourcesResult(
            resources: [new Resource(name: 'a', uri: 'file:///a')],
            ttlMs: 60000,
            cacheScope: CacheScope::Public,
            nextCursor: new Cursor(cursor: 'cur-1'),
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = $result->rebuildWithMeta(new GenericResultMetaObject(extras: ['replaced' => true]));

        self::assertSame(
            ['_meta' => ['replaced' => true]] + $result->toArray(),
            $rebuilt->toArray(),
        );
    }

    public function testToArrayWithMetaAndNextCursor(): void
    {
        $result = new ListResourcesResult(
            resources: [new Resource(name: 'a', uri: 'file:///a')],
            ttlMs: 60000,
            cacheScope: CacheScope::Public,
            nextCursor: new Cursor(cursor: 'cur-1'),
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'resources' => [['name' => 'a', 'uri' => 'file:///a']],
                'nextCursor' => 'cur-1',
                'ttlMs' => 60000,
                'cacheScope' => 'public',
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ListResourcesResult(
            resources: [new Resource(name: 'a', uri: 'file:///a')],
            ttlMs: 60000,
            cacheScope: CacheScope::Public,
            nextCursor: new Cursor(cursor: 'cur-1'),
            meta: new GenericResultMetaObject(extras: ['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesEmptyResources(): void
    {
        $result = ListResourcesResult::fromArray(['resources' => [], 'ttlMs' => 0, 'cacheScope' => 'private']);

        self::assertSame([], $result->resources);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertNull($result->nextCursor);
        self::assertSame([], $result->meta->toArray());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $result = ListResourcesResult::fromArray([
            'resources' => [
                ['name' => 'a', 'uri' => 'file:///a'],
                ['name' => 'b', 'uri' => 'file:///b'],
            ],
            'ttlMs' => 60000,
            'cacheScope' => 'public',
            'nextCursor' => 'cur-1',
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertCount(2, $result->resources);
        self::assertSame('a', $result->resources[0]->name);
        self::assertSame('file:///a', $result->resources[0]->uri);
        self::assertSame(60000, $result->ttlMs);
        self::assertSame(CacheScope::Public, $result->cacheScope);
        self::assertNotNull($result->nextCursor);
        self::assertSame('cur-1', $result->nextCursor->cursor);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListResourcesResult(
            resources: [new Resource(name: 'a', uri: 'file:///a', title: 'A')],
            ttlMs: 60000,
            cacheScope: CacheScope::Public,
            nextCursor: new Cursor(cursor: 'cur-1'),
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = ListResourcesResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListResources(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.resources" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new ListResourcesResult(resources: [5 => new Resource(name: 'a', uri: 'file:///a')], ttlMs: 0, cacheScope: CacheScope::Private);
    }

    public function testConstructorRejectsNonResourceElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ListResourcesResult(resources: [42], ttlMs: 0, cacheScope: CacheScope::Private);
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.ttlMs" must be a non-negative integer, -1 given.');

        new ListResourcesResult(resources: [], ttlMs: -1, cacheScope: CacheScope::Private);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ListResourcesResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing resources' => [
            [],
            '"result" is missing the required "resources" key.',
        ];

        yield 'resources not an array' => [
            ['resources' => 'oops'],
            '"result.resources" must be a list, string given.',
        ];

        yield 'resource entry not an object' => [
            ['resources' => ['oops']],
            'each "result.resource" must be an object, string given.',
        ];

        yield 'resource entry list-keyed' => [
            ['resources' => [['x']]],
            'each "result.resource" must be a string-keyed object.',
        ];

        yield 'missing ttlMs' => [
            ['resources' => []],
            '"result" is missing the required "ttlMs" key.',
        ];

        yield 'ttlMs not an integer' => [
            ['resources' => [], 'ttlMs' => 'oops'],
            '"result.ttlMs" must be an integer, string given.',
        ];

        yield 'missing cacheScope' => [
            ['resources' => [], 'ttlMs' => 0],
            '"result" is missing the required "cacheScope" key.',
        ];

        yield 'cacheScope not a known value' => [
            ['resources' => [], 'ttlMs' => 0, 'cacheScope' => 'shared'],
            '"result.cacheScope" must be one of [\'public\', \'private\'], \'shared\' given.',
        ];

        yield 'nextCursor not a string' => [
            ['resources' => [], 'ttlMs' => 0, 'cacheScope' => 'private', 'nextCursor' => 1],
            '"result.nextCursor" must be a non-empty string, int given.',
        ];

        yield '_meta not an object' => [
            ['resources' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['resources' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => ['x']],
            '"result._meta" must be a string-keyed object.',
        ];
    }
}
