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
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
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
        $result = new ListToolsResult([], 0, CacheScope::Private);

        self::assertSame([], $result->tools);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertNull($result->nextCursor);
        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructionAcceptsTools(): void
    {
        $tool = new Tool('read-file', ['type' => 'object']);
        $result = new ListToolsResult([$tool], 0, CacheScope::Private);

        self::assertCount(1, $result->tools);
        self::assertSame($tool, $result->tools[0]);
    }

    public function testToArrayMinimal(): void
    {
        $result = new ListToolsResult([], 0, CacheScope::Private);

        self::assertSame(
            ['resultType' => 'complete', 'ttlMs' => 0, 'cacheScope' => 'private', 'tools' => []],
            $result->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new ListToolsResult(
            [new Tool('read-file', ['type' => 'object'])],
            60000,
            CacheScope::Public,
            new Cursor('cursor-1'),
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'ttlMs' => 60000,
                'cacheScope' => 'public',
                'nextCursor' => 'cursor-1',
                'tools' => [['name' => 'read-file', 'inputSchema' => ['type' => 'object']]],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ListToolsResult([new Tool('read-file', ['type' => 'object'])], 0, CacheScope::Private);

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListToolsResult(
            [new Tool('read-file', ['type' => 'object'])],
            60000,
            CacheScope::Public,
            new Cursor('cursor-1'),
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = ListToolsResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListTools(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.tools" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new ListToolsResult([5 => new Tool('read-file', ['type' => 'object'])], 0, CacheScope::Private);
    }

    public function testConstructorRejectsNonToolEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ListToolsResult([42], 0, CacheScope::Private);
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.ttlMs" must be a non-negative integer, -1 given.');

        new ListToolsResult([], -1, CacheScope::Private);
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
            '"result" missing the required "tools" key.',
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
            '"result" missing the required "ttlMs" key.',
        ];

        yield 'ttlMs not an integer' => [
            ['tools' => [], 'ttlMs' => 'oops'],
            '"result.ttlMs" must be an integer, string given.',
        ];

        yield 'missing cacheScope' => [
            ['tools' => [], 'ttlMs' => 0],
            '"result" missing the required "cacheScope" key.',
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
