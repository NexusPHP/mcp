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
use Nexus\Mcp\Core\Schema\Elicitation\EnumOption;
use Nexus\Mcp\Core\Schema\Elicitation\TitledMultiSelectEnumSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TitledMultiSelectEnumSchema::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TitledMultiSelectEnumSchemaTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $opt = new EnumOption('r', 'Read');
        $schema = new TitledMultiSelectEnumSchema([$opt]);

        self::assertCount(1, $schema->items);
        self::assertSame('r', $schema->items[0]->const);
    }

    public function testToArrayMinimal(): void
    {
        $schema = new TitledMultiSelectEnumSchema([new EnumOption('r', 'Read')]);

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
            [new EnumOption('r', 'Read'), new EnumOption('w', 'Write')],
            'Perms',
            'Pick perms.',
            1,
            2,
            ['r'],
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
        $schema = new TitledMultiSelectEnumSchema([new EnumOption('r', 'Read')]);

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new TitledMultiSelectEnumSchema(
            [new EnumOption('r', 'Read'), new EnumOption('w', 'Write')],
            'Perms',
            'desc',
            1,
            2,
            ['r'],
        );

        $rebuilt = TitledMultiSelectEnumSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListItems(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('TitledMultiSelectEnumSchema items must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new TitledMultiSelectEnumSchema(['k' => new EnumOption('a', 'A')]);
    }

    public function testConstructorRejectsNonEnumOptionEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new TitledMultiSelectEnumSchema([42]);
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('TitledMultiSelectEnumSchema title must be a non-empty string or null.');

        new TitledMultiSelectEnumSchema([new EnumOption('r', 'Read')], '');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('TitledMultiSelectEnumSchema description must be a non-empty string or null.');

        new TitledMultiSelectEnumSchema([new EnumOption('r', 'Read')], null, '');
    }

    public function testConstructorRejectsNegativeMinItems(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('TitledMultiSelectEnumSchema minItems must be a non-negative integer or null.');

        new TitledMultiSelectEnumSchema([new EnumOption('r', 'Read')], null, null, -1);
    }

    public function testConstructorRejectsNegativeMaxItems(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('TitledMultiSelectEnumSchema maxItems must be a non-negative integer or null.');

        new TitledMultiSelectEnumSchema([new EnumOption('r', 'Read')], null, null, null, -1);
    }

    public function testConstructorRejectsNonListDefault(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('TitledMultiSelectEnumSchema default must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new TitledMultiSelectEnumSchema([new EnumOption('r', 'Read')], null, null, null, null, ['k' => 'v']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        TitledMultiSelectEnumSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing type' => [
            ['items' => ['anyOf' => []]],
            'TitledMultiSelectEnumSchema wire data missing "type".',
        ];

        yield 'wrong type literal' => [
            ['type' => 'string', 'items' => ['anyOf' => []]],
            'TitledMultiSelectEnumSchema wire "type" must be "array", \'string\' given.',
        ];

        yield 'missing items' => [
            ['type' => 'array'],
            'TitledMultiSelectEnumSchema wire data missing "items".',
        ];

        yield 'items not an object' => [
            ['type' => 'array', 'items' => 'oops'],
            'TitledMultiSelectEnumSchema wire "items" must be an object, string given.',
        ];

        yield 'items list-keyed' => [
            ['type' => 'array', 'items' => ['x']],
            'TitledMultiSelectEnumSchema wire "items" must be a string-keyed object.',
        ];

        yield 'items.anyOf missing' => [
            ['type' => 'array', 'items' => []],
            'TitledMultiSelectEnumSchema wire items.anyOf must be a list, null given.',
        ];

        yield 'items.anyOf not a list' => [
            ['type' => 'array', 'items' => ['anyOf' => ['k' => []]]],
            'TitledMultiSelectEnumSchema wire items.anyOf must be a list, got non-list array.',
        ];

        yield 'items.anyOf entry not an object' => [
            ['type' => 'array', 'items' => ['anyOf' => ['oops']]],
            'TitledMultiSelectEnumSchema wire items.anyOf entry must be an object, string given.',
        ];

        yield 'items.anyOf entry list-keyed' => [
            ['type' => 'array', 'items' => ['anyOf' => [['x']]]],
            'TitledMultiSelectEnumSchema wire items.anyOf entry must be a string-keyed object.',
        ];

        yield 'title not a string' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'title' => 1],
            'TitledMultiSelectEnumSchema wire "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'description' => 1],
            'TitledMultiSelectEnumSchema wire "description" must be a string or null, int given.',
        ];

        yield 'minItems not an int' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'minItems' => 'x'],
            'TitledMultiSelectEnumSchema wire "minItems" must be an int or null, string given.',
        ];

        yield 'maxItems not an int' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'maxItems' => 'x'],
            'TitledMultiSelectEnumSchema wire "maxItems" must be an int or null, string given.',
        ];

        yield 'default not a list' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'default' => ['k' => 'v']],
            'TitledMultiSelectEnumSchema wire "default" must be a list, got non-list array.',
        ];

        yield 'default entry not a string' => [
            ['type' => 'array', 'items' => ['anyOf' => []], 'default' => [1]],
            'TitledMultiSelectEnumSchema wire default entry must be a string, int given.',
        ];
    }
}
