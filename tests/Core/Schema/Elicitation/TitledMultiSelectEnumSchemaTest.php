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

use Nexus\Mcp\Core\Schema\Elicitation\EnumOption;
use Nexus\Mcp\Core\Schema\Elicitation\TitledMultiSelectEnumSchema;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(TitledMultiSelectEnumSchema::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TitledMultiSelectEnumSchemaTest extends AbstractMcpTestCase
{
    public function testConstructionMinimal(): void
    {
        $opt = new EnumOption(const: 'r', title: 'Read');
        $schema = new TitledMultiSelectEnumSchema(items: [$opt]);

        self::assertCount(1, $schema->items);
        self::assertSame('r', $schema->items[0]->const);
    }

    public function testToArrayMinimal(): void
    {
        $schema = new TitledMultiSelectEnumSchema(items: [new EnumOption(const: 'r', title: 'Read')]);

        self::assertSame(
            [
                'type' => 'array',
                'items' => [
                    'anyOf' => [['const' => 'r', 'title' => 'Read']],
                ],
            ],
            $schema->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $schema = new TitledMultiSelectEnumSchema(
            items: [new EnumOption(const: 'r', title: 'Read'), new EnumOption(const: 'w', title: 'Write')],
            title: 'Perms',
            description: 'Pick perms.',
            minItems: 1,
            maxItems: 2,
            default: ['r'],
        );

        self::assertSame(
            [
                'type' => 'array',
                'items' => [
                    'anyOf' => [
                        ['const' => 'r', 'title' => 'Read'],
                        ['const' => 'w', 'title' => 'Write'],
                    ],
                ],
                'title' => 'Perms',
                'description' => 'Pick perms.',
                'minItems' => 1,
                'maxItems' => 2,
                'default' => ['r'],
            ],
            $schema->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $schema = new TitledMultiSelectEnumSchema(items: [new EnumOption(const: 'r', title: 'Read')]);

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new TitledMultiSelectEnumSchema(
            items: [new EnumOption(const: 'r', title: 'Read'), new EnumOption(const: 'w', title: 'Write')],
            title: 'Perms',
            description: 'desc',
            minItems: 1,
            maxItems: 2,
            default: ['r'],
        );

        $rebuilt = TitledMultiSelectEnumSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('titled multi-select enum schema "items" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new TitledMultiSelectEnumSchema(items: ['k' => new EnumOption(const: 'a', title: 'A')]);
    }

    public function testConstructorRejectsNonEnumOptionEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type
        new TitledMultiSelectEnumSchema(items: [42]);
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('titled multi-select enum schema "title" must be a non-empty string or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new TitledMultiSelectEnumSchema(items: [new EnumOption(const: 'r', title: 'Read')], title: '');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('titled multi-select enum schema "description" must be a non-empty string or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new TitledMultiSelectEnumSchema(items: [new EnumOption(const: 'r', title: 'Read')], description: '');
    }

    public function testConstructorRejectsNegativeMinItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('titled multi-select enum schema "minItems" must be a non-negative integer or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new TitledMultiSelectEnumSchema(items: [new EnumOption(const: 'r', title: 'Read')], minItems: -1);
    }

    public function testConstructorRejectsNegativeMaxItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('titled multi-select enum schema "maxItems" must be a non-negative integer or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new TitledMultiSelectEnumSchema(items: [new EnumOption(const: 'r', title: 'Read')], maxItems: -1);
    }

    public function testConstructorRejectsNonListDefault(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('titled multi-select enum schema "default" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new TitledMultiSelectEnumSchema(items: [new EnumOption(const: 'r', title: 'Read')], default: ['k' => 'v']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        TitledMultiSelectEnumSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            ['items' => ['anyOf' => []]],
            'titled multi-select enum schema is missing the required "type" key.',
        ];

        yield 'wrong type literal' => [
            ['type' => 'string', 'items' => ['anyOf' => []]],
            'titled multi-select enum schema "type" must be \'array\', \'string\' given.',
        ];

        yield 'missing items' => [
            ['type' => 'array'],
            'titled multi-select enum schema is missing the required "items" key.',
        ];

        yield 'items not an object' => [
            ['type' => 'array', 'items' => 'oops'],
            'titled multi-select enum schema "items" must be an object, string given.',
        ];

        yield 'items list-keyed' => [
            ['type' => 'array', 'items' => ['x']],
            'titled multi-select enum schema "items" must be a string-keyed object.',
        ];

        yield 'items.anyOf missing' => [
            ['type' => 'array', 'items' => []],
            'titled multi-select enum schema "items.anyOf" must be a list, null given.',
        ];

        yield 'items.anyOf not a list' => [
            ['type' => 'array', 'items' => ['anyOf' => ['k' => []]]],
            'titled multi-select enum schema "items.anyOf" must be a list, non-list array given.',
        ];

        yield 'items.anyOf entry not an object' => [
            ['type' => 'array', 'items' => ['anyOf' => ['oops']]],
            'each titled multi-select enum schema "items.anyOf" must be an object, string given.',
        ];

        yield 'items.anyOf entry list-keyed' => [
            ['type' => 'array', 'items' => ['anyOf' => [['x']]]],
            'each titled multi-select enum schema "items.anyOf" must be a string-keyed object.',
        ];

        yield 'title not a string' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'title' => 1],
            'titled multi-select enum schema "title" must be a non-empty string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'description' => 1],
            'titled multi-select enum schema "description" must be a non-empty string or null, int given.',
        ];

        yield 'minItems not an int' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'minItems' => 'x'],
            'titled multi-select enum schema "minItems" must be a non-negative integer or null, string given.',
        ];

        yield 'maxItems not an int' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'maxItems' => 'x'],
            'titled multi-select enum schema "maxItems" must be a non-negative integer or null, string given.',
        ];

        yield 'default not a list' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'default' => ['k' => 'v']],
            'titled multi-select enum schema "default" must be a list, non-list array given.',
        ];

        yield 'default entry not a string' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'default' => [1]],
            'each titled multi-select enum schema "default" must be a string, int given.',
        ];
    }
}
