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
use Nexus\Mcp\Core\Schema\Result;
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
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListToolsResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $result = new ListToolsResult([]);

        self::assertSame([], $result->tools);
        self::assertNull($result->nextCursor);
        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructionAcceptsTools(): void
    {
        $tool = new Tool('read-file', ['type' => 'object']);
        $result = new ListToolsResult([$tool]);

        self::assertCount(1, $result->tools);
        self::assertSame($tool, $result->tools[0]);
    }

    public function testToArrayMinimal(): void
    {
        $result = new ListToolsResult([]);

        self::assertSame(['resultType' => 'complete', 'tools' => []], $result->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new ListToolsResult(
            [new Tool('read-file', ['type' => 'object'])],
            new Cursor('cursor-1'),
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
                'nextCursor' => 'cursor-1',
                'tools' => [['name' => 'read-file', 'inputSchema' => ['type' => 'object']]],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ListToolsResult([new Tool('read-file', ['type' => 'object'])]);

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ListToolsResult(
            [new Tool('read-file', ['type' => 'object'])],
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
        new ListToolsResult([5 => new Tool('read-file', ['type' => 'object'])]);
    }

    public function testConstructorRejectsNonToolEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ListToolsResult([42]);
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

        yield 'nextCursor not a string' => [
            ['tools' => [], 'nextCursor' => 1],
            '"result.nextCursor" must be a string, int given.',
        ];

        yield '_meta not an object' => [
            ['tools' => [], '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];
    }
}
