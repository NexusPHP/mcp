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

namespace Nexus\Mcp\Tests\Core\Schema\Elicitation;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Elicitation\UntitledMultiSelectEnumSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UntitledMultiSelectEnumSchema::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class UntitledMultiSelectEnumSchemaTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $schema = new UntitledMultiSelectEnumSchema(items: ['a', 'b']);

        self::assertSame(['a', 'b'], $schema->items);
        self::assertNull($schema->title);
        self::assertNull($schema->description);
        self::assertNull($schema->minItems);
        self::assertNull($schema->maxItems);
        self::assertNull($schema->default);
    }

    public function testToArrayMinimal(): void
    {
        $schema = new UntitledMultiSelectEnumSchema(items: ['a']);

        self::assertSame(
            [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['a']],
            ],
            $schema->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $schema = new UntitledMultiSelectEnumSchema(items: ['x', 'y'], title: 'Things', description: 'Pick some', minItems: 1, maxItems: 2, default: ['x']);

        self::assertSame(
            [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => ['x', 'y']],
                'title' => 'Things',
                'description' => 'Pick some',
                'minItems' => 1,
                'maxItems' => 2,
                'default' => ['x'],
            ],
            $schema->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $schema = new UntitledMultiSelectEnumSchema(items: ['a'], title: 'T', minItems: 1);

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new UntitledMultiSelectEnumSchema(items: ['x', 'y'], title: 'T', description: 'desc', minItems: 1, maxItems: 2, default: ['x']);

        $rebuilt = UntitledMultiSelectEnumSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListItems(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('untitled multi-select enum schema "items" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new UntitledMultiSelectEnumSchema(items: ['k' => 'v']);
    }

    public function testConstructorRejectsEmptyItemsEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each untitled multi-select enum schema "items" must be a non-empty string.');

        new UntitledMultiSelectEnumSchema(items: ['']);
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('untitled multi-select enum schema "title" must be a non-empty string or null.');

        new UntitledMultiSelectEnumSchema(items: ['a'], title: '');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('untitled multi-select enum schema "description" must be a non-empty string or null.');

        new UntitledMultiSelectEnumSchema(items: ['a'], description: '');
    }

    public function testConstructorRejectsNegativeMinItems(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('untitled multi-select enum schema "minItems" must be a non-negative integer or null.');

        new UntitledMultiSelectEnumSchema(items: ['a'], minItems: -1);
    }

    public function testConstructorRejectsNegativeMaxItems(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('untitled multi-select enum schema "maxItems" must be a non-negative integer or null.');

        new UntitledMultiSelectEnumSchema(items: ['a'], maxItems: -1);
    }

    public function testConstructorRejectsNonListDefault(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('untitled multi-select enum schema "default" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new UntitledMultiSelectEnumSchema(items: ['a'], default: ['k' => 'v']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        UntitledMultiSelectEnumSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            ['items' => []],
            'untitled multi-select enum schema missing the required "type" key.',
        ];

        yield 'wrong type literal' => [
            ['type' => 'string', 'items' => []],
            'untitled multi-select enum schema "type" must be \'array\', \'string\' given.',
        ];

        yield 'missing items' => [
            ['type' => 'array'],
            'untitled multi-select enum schema missing the required "items" key.',
        ];

        yield 'items not an object' => [
            ['type' => 'array', 'items' => 'oops'],
            'untitled multi-select enum schema "items" must be an object, string given.',
        ];

        yield 'items list-keyed' => [
            ['type' => 'array', 'items' => ['x']],
            'untitled multi-select enum schema "items" must be a string-keyed object.',
        ];

        yield 'items.type wrong literal' => [
            ['type' => 'array', 'items' => ['type' => 'number', 'enum' => ['a']]],
            'untitled multi-select enum schema "items.type" must be \'string\', \'number\' given.',
        ];

        yield 'items.enum missing' => [
            ['type' => 'array', 'items' => ['type' => 'string']],
            'untitled multi-select enum schema "items.enum" must be a list, null given.',
        ];

        yield 'items.enum not a list' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['k' => 'v']]],
            'untitled multi-select enum schema "items.enum" must be a list, non-list array given.',
        ];

        yield 'items.enum entry not a string' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => [1]]],
            'each untitled multi-select enum schema "items.enum" must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'title' => 1],
            'untitled multi-select enum schema "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'description' => 1],
            'untitled multi-select enum schema "description" must be a string or null, int given.',
        ];

        yield 'minItems not an int' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'minItems' => 'x'],
            'untitled multi-select enum schema "minItems" must be an int or null, string given.',
        ];

        yield 'maxItems not an int' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'maxItems' => 'x'],
            'untitled multi-select enum schema "maxItems" must be an int or null, string given.',
        ];

        yield 'default not a list' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'default' => ['k' => 'v']],
            'untitled multi-select enum schema "default" must be a list, non-list array given.',
        ];

        yield 'default entry not a string' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'default' => [1]],
            'each untitled multi-select enum schema "default" must be a string, int given.',
        ];
    }
}
