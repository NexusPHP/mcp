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
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReadResourceResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ReadResourceResultTest extends TestCase
{
    public function testConstructionAcceptsEmptyContentsList(): void
    {
        $result = new ReadResourceResult([]);

        self::assertSame([], $result->contents);
        self::assertNull($result->meta);
    }

    public function testToArrayEmitsTextContents(): void
    {
        $result = new ReadResourceResult([
            new TextResourceContents('file:///x', 'hello', 'text/plain'),
        ]);

        self::assertSame(
            [
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
        ]);

        self::assertSame(
            [
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
        ]);

        self::assertSame(
            [
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
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'contents' => [['uri' => 'file:///x', 'text' => 'hi']],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ReadResourceResult(
            [new TextResourceContents('file:///x', 'hi')],
            new MetaObject(['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesEmptyContents(): void
    {
        $result = ReadResourceResult::fromArray(['contents' => []]);

        self::assertSame([], $result->contents);
    }

    public function testFromArrayParsesMixedContents(): void
    {
        $result = ReadResourceResult::fromArray([
            'contents' => [
                ['uri' => 'file:///a', 'text' => 'hi'],
                ['uri' => 'file:///b', 'blob' => 'aGVsbG8='],
            ],
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
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertNotNull($result->meta);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ReadResourceResult(
            [
                new TextResourceContents('file:///a', 'hi', 'text/plain'),
                new BlobResourceContents('file:///b', 'aGVsbG8=', 'application/octet-stream'),
            ],
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame($original->toArray(), ReadResourceResult::fromArray($original->toArray())->toArray());
    }

    public function testConstructorRejectsNonListContents(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ReadResourceResult contents must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new ReadResourceResult([5 => new TextResourceContents('file:///x', 'hi')]);
    }

    public function testConstructorRejectsNonResourceContentsElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ReadResourceResult([42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ReadResourceResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing contents' => [
            [],
            'ReadResourceResult data missing "contents".',
        ];

        yield 'contents not an array' => [
            ['contents' => 'oops'],
            'ReadResourceResult "contents" must be a list, string given.',
        ];

        yield 'contents entry not an object' => [
            ['contents' => ['oops']],
            'ReadResourceResult contents entry must be an object, string given.',
        ];

        yield 'contents entry list-keyed' => [
            ['contents' => [['x']]],
            'ReadResourceResult contents entry must be a string-keyed object.',
        ];

        yield 'contents entry missing discriminator' => [
            ['contents' => [['uri' => 'file:///x']]],
            'ResourceContents data must have either "text" or "blob".',
        ];

        yield '_meta not an object' => [
            ['contents' => [], '_meta' => 'oops'],
            'Result "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['contents' => [], '_meta' => ['x']],
            'Result "_meta" must be a string-keyed object.',
        ];
    }
}
