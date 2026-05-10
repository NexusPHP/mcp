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
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Result;
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
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListPromptsResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $result = new ListPromptsResult([new Prompt('code-review')]);

        self::assertCount(1, $result->prompts);
        self::assertNull($result->nextCursor);
        self::assertNull($result->meta);
    }

    public function testConstructionAcceptsEmptyList(): void
    {
        $result = new ListPromptsResult([]);

        self::assertSame([], $result->prompts);
    }

    public function testToArrayEmitsPrompts(): void
    {
        $result = new ListPromptsResult([
            new Prompt('a'),
            new Prompt('b'),
        ]);

        self::assertSame(
            ['prompts' => [['name' => 'a'], ['name' => 'b']]],
            $result->toArray(),
        );
    }

    public function testToArrayWithMetaAndNextCursor(): void
    {
        $result = new ListPromptsResult(
            [new Prompt('a')],
            new Cursor('cur-1'),
            new Meta(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
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
            new Cursor('cur-1'),
            new Meta(['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $result = ListPromptsResult::fromArray([
            'prompts' => [['name' => 'a'], ['name' => 'b']],
            'nextCursor' => 'cur-1',
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertCount(2, $result->prompts);
        self::assertSame('a', $result->prompts[0]->name);
        self::assertNotNull($result->nextCursor);
        self::assertSame('cur-1', $result->nextCursor->cursor);
        self::assertNotNull($result->meta);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListPromptsResult(
            [new Prompt('a', 'A')],
            new Cursor('cur-1'),
            new Meta(['vendor' => 'x']),
        );

        $rebuilt = ListPromptsResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListPrompts(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ListPromptsResult prompts must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new ListPromptsResult([5 => new Prompt('a')]);
    }

    public function testConstructorRejectsNonPromptElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ListPromptsResult([42]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ListPromptsResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing prompts' => [
            [],
            'ListPromptsResult wire data missing "prompts".',
        ];

        yield 'prompts not an array' => [
            ['prompts' => 'oops'],
            'ListPromptsResult wire "prompts" must be an array, string given.',
        ];

        yield 'prompt entry not an object' => [
            ['prompts' => ['oops']],
            'ListPromptsResult wire prompt entry must be an object, string given.',
        ];

        yield 'prompt entry list-keyed' => [
            ['prompts' => [['x']]],
            'ListPromptsResult wire prompt entry must be a string-keyed object.',
        ];

        yield 'nextCursor not a string' => [
            ['prompts' => [], 'nextCursor' => 1],
            'ListPromptsResult wire "nextCursor" must be a string, int given.',
        ];

        yield '_meta not an object' => [
            ['prompts' => [], '_meta' => 'oops'],
            'Result "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['prompts' => [], '_meta' => ['x']],
            'Result "_meta" must be a string-keyed object.',
        ];
    }
}
