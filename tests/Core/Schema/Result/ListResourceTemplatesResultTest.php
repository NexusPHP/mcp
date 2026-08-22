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

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ListResourceTemplatesResult::class)]
#[CoversClass(PaginatedResult::class)]
#[CoversClass(CacheableResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListResourceTemplatesResultTest extends AbstractMcpTestCase
{
    public function testConstructionDefaults(): void
    {
        $result = new ListResourceTemplatesResult(resourceTemplates: [new ResourceTemplate(name: 't', uriTemplate: 'file:///{name}')], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertCount(1, $result->resourceTemplates);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertNull($result->nextCursor);
        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructionAcceptsEmptyList(): void
    {
        $result = new ListResourceTemplatesResult(resourceTemplates: [], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame([], $result->resourceTemplates);
    }

    public function testToArrayEmitsResourceTemplates(): void
    {
        $result = new ListResourceTemplatesResult(resourceTemplates: [
            new ResourceTemplate(name: 'a', uriTemplate: 'file:///{a}'),
            new ResourceTemplate(name: 'b', uriTemplate: 'file:///{b}'),
        ], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame(
            [
                'resultType' => 'complete',
                'resourceTemplates' => [
                    ['name' => 'a', 'uriTemplate' => 'file:///{a}'],
                    ['name' => 'b', 'uriTemplate' => 'file:///{b}'],
                ],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
            $result->toArray(),
        );
    }

    public function testToArrayIncludesNextCursor(): void
    {
        $result = new ListResourceTemplatesResult(
            resourceTemplates: [new ResourceTemplate(name: 'a', uriTemplate: 'file:///{a}')],
            ttlMs: 0,
            cacheScope: CacheScope::Private,
            nextCursor: new Cursor(cursor: 'cur-1'),
        );

        self::assertSame(
            [
                'resultType' => 'complete',
                'resourceTemplates' => [['name' => 'a', 'uriTemplate' => 'file:///{a}']],
                'nextCursor' => 'cur-1',
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
            $result->toArray(),
        );
    }

    public function testToArrayIncludesMeta(): void
    {
        $result = new ListResourceTemplatesResult(
            resourceTemplates: [new ResourceTemplate(name: 'a', uriTemplate: 'file:///{a}')],
            ttlMs: 0,
            cacheScope: CacheScope::Private,
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'resourceTemplates' => [['name' => 'a', 'uriTemplate' => 'file:///{a}']],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
            $result->toArray(),
        );
    }

    public function testRebuildingWithNewMetaKeepsEveryOtherField(): void
    {
        $result = new ListResourceTemplatesResult(
            resourceTemplates: [new ResourceTemplate(name: 'a', uriTemplate: 'file:///{a}')],
            ttlMs: 60_000,
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
        $result = new ListResourceTemplatesResult(
            resourceTemplates: [new ResourceTemplate(name: 'a', uriTemplate: 'file:///{a}')],
            ttlMs: 60_000,
            cacheScope: CacheScope::Public,
            nextCursor: new Cursor(cursor: 'cur-1'),
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'resourceTemplates' => [['name' => 'a', 'uriTemplate' => 'file:///{a}']],
                'nextCursor' => 'cur-1',
                'ttlMs' => 60_000,
                'cacheScope' => 'public',
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ListResourceTemplatesResult(
            resourceTemplates: [new ResourceTemplate(name: 'a', uriTemplate: 'file:///{a}')],
            ttlMs: 60_000,
            cacheScope: CacheScope::Public,
            nextCursor: new Cursor(cursor: 'cur-1'),
            meta: new GenericResultMetaObject(extras: ['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesEmptyList(): void
    {
        $result = ListResourceTemplatesResult::fromArray(['resourceTemplates' => [], 'ttlMs' => 0, 'cacheScope' => 'private']);

        self::assertSame([], $result->resourceTemplates);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertNull($result->nextCursor);
        self::assertSame([], $result->meta->toArray());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $result = ListResourceTemplatesResult::fromArray([
            'resourceTemplates' => [
                ['name' => 'a', 'uriTemplate' => 'file:///{a}'],
                ['name' => 'b', 'uriTemplate' => 'file:///{b}'],
            ],
            'ttlMs' => 60_000,
            'cacheScope' => 'public',
            'nextCursor' => 'cur-1',
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertCount(2, $result->resourceTemplates);
        self::assertSame('a', $result->resourceTemplates[0]->name);
        self::assertSame('file:///{a}', $result->resourceTemplates[0]->uriTemplate);
        self::assertSame(60_000, $result->ttlMs);
        self::assertSame(CacheScope::Public, $result->cacheScope);
        self::assertNotNull($result->nextCursor);
        self::assertSame('cur-1', $result->nextCursor->cursor);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListResourceTemplatesResult(
            resourceTemplates: [new ResourceTemplate(name: 'a', uriTemplate: 'file:///{a}', title: 'A')],
            ttlMs: 60_000,
            cacheScope: CacheScope::Public,
            nextCursor: new Cursor(cursor: 'cur-1'),
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = ListResourceTemplatesResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result.resourceTemplates" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new ListResourceTemplatesResult(resourceTemplates: [5 => new ResourceTemplate(name: 'a', uriTemplate: 'file:///{a}')], ttlMs: 0, cacheScope: CacheScope::Private);
    }

    public function testConstructorRejectsNonResourceTemplateElement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type
        new ListResourceTemplatesResult(resourceTemplates: [42], ttlMs: 0, cacheScope: CacheScope::Private);
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result.ttlMs" must be a non-negative integer, -1 given.');

        new ListResourceTemplatesResult(resourceTemplates: [], ttlMs: -1, cacheScope: CacheScope::Private);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ListResourceTemplatesResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing resourceTemplates' => [
            [],
            '"result" is missing the required "resourceTemplates" key.',
        ];

        yield 'resourceTemplates not an array' => [
            ['resourceTemplates' => 'oops'],
            '"result.resourceTemplates" must be a list, string given.',
        ];

        yield 'entry not an object' => [
            ['resourceTemplates' => ['oops']],
            'each "result.resourceTemplate" must be an object, string given.',
        ];

        yield 'entry list-keyed' => [
            ['resourceTemplates' => [['x']]],
            'each "result.resourceTemplate" must be a string-keyed object.',
        ];

        yield 'missing ttlMs' => [
            ['resourceTemplates' => []],
            '"result" is missing the required "ttlMs" key.',
        ];

        yield 'ttlMs not an integer' => [
            ['resourceTemplates' => [], 'ttlMs' => 'oops'],
            '"result.ttlMs" must be an integer, string given.',
        ];

        yield 'missing cacheScope' => [
            ['resourceTemplates' => [], 'ttlMs' => 0],
            '"result" is missing the required "cacheScope" key.',
        ];

        yield 'cacheScope not a known value' => [
            ['resourceTemplates' => [], 'ttlMs' => 0, 'cacheScope' => 'shared'],
            '"result.cacheScope" must be one of [\'public\', \'private\'], \'shared\' given.',
        ];

        yield 'nextCursor not a string' => [
            ['resourceTemplates' => [], 'ttlMs' => 0, 'cacheScope' => 'private', 'nextCursor' => 1],
            '"result.nextCursor" must be a non-empty string, int given.',
        ];

        yield '_meta not an object' => [
            ['resourceTemplates' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['resourceTemplates' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => ['x']],
            '"result._meta" must be a string-keyed object.',
        ];
    }
}
