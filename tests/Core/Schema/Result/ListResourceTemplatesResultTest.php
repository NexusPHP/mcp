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
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\PaginatedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListResourceTemplatesResult::class)]
#[CoversClass(PaginatedResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListResourceTemplatesResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $result = new ListResourceTemplatesResult([new ResourceTemplate('t', 'file:///{name}')]);

        self::assertCount(1, $result->resourceTemplates);
        self::assertNull($result->nextCursor);
        self::assertNull($result->meta);
    }

    public function testConstructionAcceptsEmptyList(): void
    {
        $result = new ListResourceTemplatesResult([]);

        self::assertSame([], $result->resourceTemplates);
    }

    public function testToArrayEmitsResourceTemplates(): void
    {
        $result = new ListResourceTemplatesResult([
            new ResourceTemplate('a', 'file:///{a}'),
            new ResourceTemplate('b', 'file:///{b}'),
        ]);

        self::assertSame(
            [
                'resourceTemplates' => [
                    ['name' => 'a', 'uriTemplate' => 'file:///{a}'],
                    ['name' => 'b', 'uriTemplate' => 'file:///{b}'],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayIncludesNextCursor(): void
    {
        $result = new ListResourceTemplatesResult(
            [new ResourceTemplate('a', 'file:///{a}')],
            new Cursor('cur-1'),
        );

        self::assertSame(
            [
                'nextCursor' => 'cur-1',
                'resourceTemplates' => [['name' => 'a', 'uriTemplate' => 'file:///{a}']],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayIncludesMeta(): void
    {
        $result = new ListResourceTemplatesResult(
            [new ResourceTemplate('a', 'file:///{a}')],
            null,
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resourceTemplates' => [['name' => 'a', 'uriTemplate' => 'file:///{a}']],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayWithMetaAndNextCursor(): void
    {
        $result = new ListResourceTemplatesResult(
            [new ResourceTemplate('a', 'file:///{a}')],
            new Cursor('cur-1'),
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'nextCursor' => 'cur-1',
                'resourceTemplates' => [['name' => 'a', 'uriTemplate' => 'file:///{a}']],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ListResourceTemplatesResult(
            [new ResourceTemplate('a', 'file:///{a}')],
            new Cursor('cur-1'),
            new MetaObject(['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesEmptyList(): void
    {
        $result = ListResourceTemplatesResult::fromArray(['resourceTemplates' => []]);

        self::assertSame([], $result->resourceTemplates);
        self::assertNull($result->nextCursor);
        self::assertNull($result->meta);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $result = ListResourceTemplatesResult::fromArray([
            'resourceTemplates' => [
                ['name' => 'a', 'uriTemplate' => 'file:///{a}'],
                ['name' => 'b', 'uriTemplate' => 'file:///{b}'],
            ],
            'nextCursor' => 'cur-1',
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertCount(2, $result->resourceTemplates);
        self::assertSame('a', $result->resourceTemplates[0]->name);
        self::assertSame('file:///{a}', $result->resourceTemplates[0]->uriTemplate);
        self::assertNotNull($result->nextCursor);
        self::assertSame('cur-1', $result->nextCursor->cursor);
        self::assertNotNull($result->meta);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListResourceTemplatesResult(
            [new ResourceTemplate('a', 'file:///{a}', 'A')],
            new Cursor('cur-1'),
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = ListResourceTemplatesResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonList(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ListResourceTemplatesResult resourceTemplates must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new ListResourceTemplatesResult([5 => new ResourceTemplate('a', 'file:///{a}')]);
    }

    public function testConstructorRejectsNonResourceTemplateElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ListResourceTemplatesResult([42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ListResourceTemplatesResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing resourceTemplates' => [
            [],
            'ListResourceTemplatesResult data missing "resourceTemplates".',
        ];

        yield 'resourceTemplates not an array' => [
            ['resourceTemplates' => 'oops'],
            'ListResourceTemplatesResult "resourceTemplates" must be a list, string given.',
        ];

        yield 'entry not an object' => [
            ['resourceTemplates' => ['oops']],
            'ListResourceTemplatesResult resourceTemplate entry must be an object, string given.',
        ];

        yield 'entry list-keyed' => [
            ['resourceTemplates' => [['x']]],
            'ListResourceTemplatesResult resourceTemplate entry must be a string-keyed object.',
        ];

        yield 'nextCursor not a string' => [
            ['resourceTemplates' => [], 'nextCursor' => 1],
            'ListResourceTemplatesResult "nextCursor" must be a string, int given.',
        ];

        yield '_meta not an object' => [
            ['resourceTemplates' => [], '_meta' => 'oops'],
            'Result "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['resourceTemplates' => [], '_meta' => ['x']],
            'Result "_meta" must be a string-keyed object.',
        ];
    }
}
