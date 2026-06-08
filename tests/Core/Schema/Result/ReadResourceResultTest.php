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
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CacheableResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReadResourceResult::class)]
#[CoversClass(CacheableResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ReadResourceResultTest extends TestCase
{
    public function testConstructionAcceptsEmptyContentsList(): void
    {
        $result = new ReadResourceResult([], 0, CacheScope::Private);

        self::assertSame([], $result->contents);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertSame([], $result->meta->toArray());
    }

    public function testToArrayEmitsTextContents(): void
    {
        $result = new ReadResourceResult([
            new TextResourceContents('file:///x', 'hello', 'text/plain'),
        ], 0, CacheScope::Private);

        self::assertSame(
            [
                'resultType' => 'complete',
                'ttlMs' => 0,
                'cacheScope' => 'private',
                'contents' => [
                    [
                        'uri' => 'file:///x',
                        'mimeType' => 'text/plain',
                        'text' => 'hello',
                    ],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayEmitsBlobContents(): void
    {
        $result = new ReadResourceResult([
            new BlobResourceContents('file:///x', 'aGVsbG8=', 'application/octet-stream'),
        ], 0, CacheScope::Private);

        self::assertSame(
            [
                'resultType' => 'complete',
                'ttlMs' => 0,
                'cacheScope' => 'private',
                'contents' => [
                    [
                        'uri' => 'file:///x',
                        'mimeType' => 'application/octet-stream',
                        'blob' => 'aGVsbG8=',
                    ],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayEmitsMixedContents(): void
    {
        $result = new ReadResourceResult([
            new TextResourceContents('file:///a', 'hi'),
            new BlobResourceContents('file:///b', 'aGVsbG8='),
        ], 0, CacheScope::Private);

        self::assertSame(
            [
                'resultType' => 'complete',
                'ttlMs' => 0,
                'cacheScope' => 'private',
                'contents' => [
                    ['uri' => 'file:///a', 'text' => 'hi'],
                    ['uri' => 'file:///b', 'blob' => 'aGVsbG8='],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayIncludesMeta(): void
    {
        $result = new ReadResourceResult(
            [new TextResourceContents('file:///x', 'hi')],
            0,
            CacheScope::Private,
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'ttlMs' => 0,
                'cacheScope' => 'private',
                'contents' => [['uri' => 'file:///x', 'text' => 'hi']],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ReadResourceResult(
            [new TextResourceContents('file:///x', 'hi')],
            0,
            CacheScope::Private,
            new MetaObject(['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesEmptyContents(): void
    {
        $result = ReadResourceResult::fromArray(['contents' => [], 'ttlMs' => 0, 'cacheScope' => 'private']);

        self::assertSame([], $result->contents);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testFromArrayParsesMixedContents(): void
    {
        $result = ReadResourceResult::fromArray([
            'contents' => [
                ['uri' => 'file:///a', 'text' => 'hi'],
                ['uri' => 'file:///b', 'blob' => 'aGVsbG8='],
            ],
            'ttlMs' => 0,
            'cacheScope' => 'private',
        ]);

        self::assertCount(2, $result->contents);

        if (! $result->contents[0] instanceof TextResourceContents) {
            self::fail('Expected TextResourceContents at index 0.');
        }

        self::assertSame('hi', $result->contents[0]->text);

        if (! $result->contents[1] instanceof BlobResourceContents) {
            self::fail('Expected BlobResourceContents at index 1.');
        }

        self::assertSame('aGVsbG8=', $result->contents[1]->blob);
    }

    public function testFromArrayParsesMeta(): void
    {
        $result = ReadResourceResult::fromArray([
            'contents' => [['uri' => 'file:///x', 'text' => 'hi']],
            'ttlMs' => 0,
            'cacheScope' => 'private',
            '_meta' => ['vendor' => 'x'],
        ]);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ReadResourceResult(
            [
                new TextResourceContents('file:///a', 'hi', 'text/plain'),
                new BlobResourceContents('file:///b', 'aGVsbG8=', 'application/octet-stream'),
            ],
            60000,
            CacheScope::Public,
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame($original->toArray(), ReadResourceResult::fromArray($original->toArray())->toArray());
    }

    public function testConstructorRejectsNonListContents(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.contents" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new ReadResourceResult([5 => new TextResourceContents('file:///x', 'hi')], 0, CacheScope::Private);
    }

    public function testConstructorRejectsNonResourceContentsElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ReadResourceResult([42], 0, CacheScope::Private);
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.ttlMs" must be a non-negative integer, -1 given.');

        new ReadResourceResult([], -1, CacheScope::Private);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ReadResourceResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing contents' => [
            [],
            '"result" missing the required "contents" key.',
        ];

        yield 'contents not an array' => [
            ['contents' => 'oops'],
            '"result.contents" must be a list, string given.',
        ];

        yield 'contents entry not an object' => [
            ['contents' => ['oops']],
            'each "result.contents" must be an object, string given.',
        ];

        yield 'contents entry list-keyed' => [
            ['contents' => [['x']]],
            'each "result.contents" must be a string-keyed object.',
        ];

        yield 'contents entry missing discriminator' => [
            ['contents' => [['uri' => 'file:///x']]],
            'ReadResourceResult contents data must have either "text" or "blob".',
        ];

        yield 'missing ttlMs' => [
            ['contents' => []],
            '"result" missing the required "ttlMs" key.',
        ];

        yield 'ttlMs not an integer' => [
            ['contents' => [], 'ttlMs' => 'oops'],
            '"result.ttlMs" must be an integer, string given.',
        ];

        yield 'missing cacheScope' => [
            ['contents' => [], 'ttlMs' => 0],
            '"result" missing the required "cacheScope" key.',
        ];

        yield 'cacheScope not a known value' => [
            ['contents' => [], 'ttlMs' => 0, 'cacheScope' => 'shared'],
            '"result.cacheScope" must be one of [\'public\', \'private\'], \'shared\' given.',
        ];

        yield '_meta not an object' => [
            ['contents' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['contents' => [], 'ttlMs' => 0, 'cacheScope' => 'private', '_meta' => ['x']],
            '"result._meta" must be a string-keyed object.',
        ];
    }
}
