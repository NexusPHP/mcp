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
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
use Nexus\Mcp\Core\Schema\ResultMetaObject;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListToolsResult::class)]
#[CoversClass(PaginatedResult::class)]
#[CoversClass(CacheableResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListToolsResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $result = new ListToolsResult(tools: [], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame([], $result->tools);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertNull($result->nextCursor);
        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructionAcceptsTools(): void
    {
        $tool = new Tool(name: 'read-file', inputSchema: ['type' => 'object']);
        $result = new ListToolsResult(tools: [$tool], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertCount(1, $result->tools);
        self::assertSame($tool, $result->tools[0]);
    }

    public function testToArrayMinimal(): void
    {
        $result = new ListToolsResult(tools: [], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame(
            ['resultType' => 'complete', 'tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
            $result->toArray(),
        );
    }

    public function testRebuildingWithNewMetaKeepsEveryOtherField(): void
    {
        $result = new ListToolsResult(
            tools: [new Tool(name: 'read-file', inputSchema: ['type' => 'object'])],
            ttlMs: 60000,
            cacheScope: CacheScope::Public,
            nextCursor: new Cursor(cursor: 'cursor-1'),
            meta: new ResultMetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = $result->rebuildWithMeta(new ResultMetaObject(extras: ['replaced' => true]));

        self::assertSame(
            ['_meta' => ['replaced' => true]] + $result->toArray(),
            $rebuilt->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new ListToolsResult(
            tools: [new Tool(name: 'read-file', inputSchema: ['type' => 'object'])],
            ttlMs: 60000,
            cacheScope: CacheScope::Public,
            nextCursor: new Cursor(cursor: 'cursor-1'),
            meta: new ResultMetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'tools' => [['name' => 'read-file', 'inputSchema' => ['type' => 'object']]],
                'nextCursor' => 'cursor-1',
                'ttlMs' => 60000,
                'cacheScope' => 'public',
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ListToolsResult(tools: [new Tool(name: 'read-file', inputSchema: ['type' => 'object'])], ttlMs: 0, cacheScope: CacheScope::Private);

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListToolsResult(
            tools: [new Tool(name: 'read-file', inputSchema: ['type' => 'object'])],
            ttlMs: 60000,
            cacheScope: CacheScope::Public,
            nextCursor: new Cursor(cursor: 'cursor-1'),
            meta: new ResultMetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = ListToolsResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListTools(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.tools" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new ListToolsResult(tools: [5 => new Tool(name: 'read-file', inputSchema: ['type' => 'object'])], ttlMs: 0, cacheScope: CacheScope::Private);
    }

    public function testConstructorRejectsNonToolEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ListToolsResult(tools: [42], ttlMs: 0, cacheScope: CacheScope::Private);
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.ttlMs" must be a non-negative integer, -1 given.');

        new ListToolsResult(tools: [], ttlMs: -1, cacheScope: CacheScope::Private);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ListToolsResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing tools' => [
            [],
            '"result" is missing the required "tools" key.',
        ];

        yield 'tools not an array' => [
            ['tools' => 'oops'],
            '"result.tools" must be a list, string given.',
        ];

        yield 'tool entry not an object' => [
            ['tools' => ['oops']],
            'each "result.tool" must be an object, string given.',
        ];

        yield 'tool entry list-keyed' => [
            ['tools' => [['x']]],
            'each "result.tool" must be a string-keyed object.',
        ];

        yield 'missing ttlMs' => [
            ['tools' => []],
            '"result" is missing the required "ttlMs" key.',
        ];

        yield 'ttlMs not an integer' => [
            ['tools' => [], 'ttlMs' => 'oops'],
            '"result.ttlMs" must be an integer, string given.',
        ];

        yield 'missing cacheScope' => [
            ['tools' => [], 'ttlMs' => 0],
            '"result" is missing the required "cacheScope" key.',
        ];

        yield 'cacheScope not a known value' => [
            ['tools' => [], 'ttlMs' => 0, 'cacheScope' => 'shared'],
            '"result.cacheScope" must be one of [\'public\', \'private\'], \'shared\' given.',
        ];

        yield 'nextCursor not a string' => [
            ['tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private', 'nextCursor' => 1],
            '"result.nextCursor" must be a string, int given.',
        ];

        yield '_meta not an object' => [
            ['tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];
    }
}
