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
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListPromptsResult::class)]
#[CoversClass(PaginatedResult::class)]
#[CoversClass(CacheableResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListPromptsResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $result = new ListPromptsResult([new Prompt('code-review')], 0, CacheScope::Private);

        self::assertCount(1, $result->prompts);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertNull($result->nextCursor);
        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructionAcceptsEmptyList(): void
    {
        $result = new ListPromptsResult([], 0, CacheScope::Private);

        self::assertSame([], $result->prompts);
    }

    public function testToArrayEmitsPrompts(): void
    {
        $result = new ListPromptsResult([
            new Prompt('a'),
            new Prompt('b'),
        ], 0, CacheScope::Private);

        self::assertSame(
            [
                'resultType' => 'complete',
                'ttlMs' => 0,
                'cacheScope' => 'private',
                'prompts' => [['name' => 'a'], ['name' => 'b']],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayWithMetaAndNextCursor(): void
    {
        $result = new ListPromptsResult(
            [new Prompt('a')],
            60000,
            CacheScope::Public,
            new Cursor('cur-1'),
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'ttlMs' => 60000,
                'cacheScope' => 'public',
                'nextCursor' => 'cur-1',
                'prompts' => [['name' => 'a']],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ListPromptsResult(
            [new Prompt('a')],
            60000,
            CacheScope::Public,
            new Cursor('cur-1'),
            new MetaObject(['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $result = ListPromptsResult::fromArray([
            'prompts' => [['name' => 'a'], ['name' => 'b']],
            'ttlMs' => 60000,
            'cacheScope' => 'public',
            'nextCursor' => 'cur-1',
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertCount(2, $result->prompts);
        self::assertSame('a', $result->prompts[0]->name);
        self::assertSame(60000, $result->ttlMs);
        self::assertSame(CacheScope::Public, $result->cacheScope);
        self::assertNotNull($result->nextCursor);
        self::assertSame('cur-1', $result->nextCursor->cursor);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListPromptsResult(
            [new Prompt('a', 'A')],
            60000,
            CacheScope::Public,
            new Cursor('cur-1'),
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = ListPromptsResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListPrompts(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.prompts" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new ListPromptsResult([5 => new Prompt('a')], 0, CacheScope::Private);
    }

    public function testConstructorRejectsNonPromptElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ListPromptsResult([42], 0, CacheScope::Private);
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.ttlMs" must be a non-negative integer, -1 given.');

        new ListPromptsResult([], -1, CacheScope::Private);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ListPromptsResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing prompts' => [
            [],
            '"result" missing the required "prompts" key.',
        ];

        yield 'prompts not an array' => [
            ['prompts' => 'oops'],
            '"result.prompts" must be a list, string given.',
        ];

        yield 'prompt entry not an object' => [
            ['prompts' => ['oops']],
            'each "result.prompt" must be an object, string given.',
        ];

        yield 'prompt entry list-keyed' => [
            ['prompts' => [['x']]],
            'each "result.prompt" must be a string-keyed object.',
        ];

        yield 'missing ttlMs' => [
            ['prompts' => []],
            '"result" missing the required "ttlMs" key.',
        ];

        yield 'ttlMs not an integer' => [
            ['prompts' => [], 'ttlMs' => 'oops'],
            '"result.ttlMs" must be an integer, string given.',
        ];

        yield 'missing cacheScope' => [
            ['prompts' => [], 'ttlMs' => 0],
            '"result" missing the required "cacheScope" key.',
        ];

        yield 'cacheScope not a known value' => [
            ['prompts' => [], 'ttlMs' => 0, 'cacheScope' => 'shared'],
            '"result.cacheScope" must be one of [\'public\', \'private\'], \'shared\' given.',
        ];

        yield 'nextCursor not a string' => [
            ['prompts' => [], 'ttlMs' => 0, 'cacheScope' => 'private', 'nextCursor' => 1],
            '"result.nextCursor" must be a string, int given.',
        ];

        yield '_meta not an object' => [
            ['prompts' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['prompts' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => ['x']],
            '"result._meta" must be a string-keyed object.',
        ];
    }
}
