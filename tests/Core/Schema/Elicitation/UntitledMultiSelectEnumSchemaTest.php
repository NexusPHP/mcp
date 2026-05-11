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
        $schema = new UntitledMultiSelectEnumSchema(['a', 'b']);

        self::assertSame(['a', 'b'], $schema->items);
        self::assertNull($schema->title);
        self::assertNull($schema->description);
        self::assertNull($schema->minItems);
        self::assertNull($schema->maxItems);
        self::assertNull($schema->default);
    }

    public function testToArrayMinimal(): void
    {
        $schema = new UntitledMultiSelectEnumSchema(['a']);

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
        $schema = new UntitledMultiSelectEnumSchema(['x', 'y'], 'Things', 'Pick some', 1, 2, ['x']);

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
        $schema = new UntitledMultiSelectEnumSchema(['a'], 'T', null, 1);

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new UntitledMultiSelectEnumSchema(['x', 'y'], 'T', 'desc', 1, 2, ['x']);

        $rebuilt = UntitledMultiSelectEnumSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListItems(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UntitledMultiSelectEnumSchema items must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new UntitledMultiSelectEnumSchema(['k' => 'v']);
    }

    public function testConstructorRejectsEmptyItemsEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UntitledMultiSelectEnumSchema items entry must be a non-empty string.');

        new UntitledMultiSelectEnumSchema(['']);
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UntitledMultiSelectEnumSchema title must be a non-empty string or null.');

        new UntitledMultiSelectEnumSchema(['a'], '');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UntitledMultiSelectEnumSchema description must be a non-empty string or null.');

        new UntitledMultiSelectEnumSchema(['a'], null, '');
    }

    public function testConstructorRejectsNegativeMinItems(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UntitledMultiSelectEnumSchema minItems must be a non-negative integer or null.');

        new UntitledMultiSelectEnumSchema(['a'], null, null, -1);
    }

    public function testConstructorRejectsNegativeMaxItems(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UntitledMultiSelectEnumSchema maxItems must be a non-negative integer or null.');

        new UntitledMultiSelectEnumSchema(['a'], null, null, null, -1);
    }

    public function testConstructorRejectsNonListDefault(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UntitledMultiSelectEnumSchema default must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new UntitledMultiSelectEnumSchema(['a'], null, null, null, null, ['k' => 'v']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        UntitledMultiSelectEnumSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing type' => [
            ['items' => []],
            'UntitledMultiSelectEnumSchema wire data missing "type".',
        ];

        yield 'wrong type literal' => [
            ['type' => 'string', 'items' => []],
            'UntitledMultiSelectEnumSchema wire "type" must be "array", \'string\' given.',
        ];

        yield 'missing items' => [
            ['type' => 'array'],
            'UntitledMultiSelectEnumSchema wire data missing "items".',
        ];

        yield 'items not an object' => [
            ['type' => 'array', 'items' => 'oops'],
            'UntitledMultiSelectEnumSchema wire "items" must be an object, string given.',
        ];

        yield 'items list-keyed' => [
            ['type' => 'array', 'items' => ['x']],
            'UntitledMultiSelectEnumSchema wire "items" must be a string-keyed object.',
        ];

        yield 'items.type wrong literal' => [
            ['type' => 'array', 'items' => ['type' => 'number', 'enum' => ['a']]],
            'UntitledMultiSelectEnumSchema wire items.type must be "string", \'number\' given.',
        ];

        yield 'items.enum missing' => [
            ['type' => 'array', 'items' => ['type' => 'string']],
            'UntitledMultiSelectEnumSchema wire items.enum must be a list, null given.',
        ];

        yield 'items.enum not a list' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['k' => 'v']]],
            'UntitledMultiSelectEnumSchema wire items.enum must be a list, got non-list array.',
        ];

        yield 'items.enum entry not a string' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => [1]]],
            'UntitledMultiSelectEnumSchema wire items.enum entry must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'title' => 1],
            'UntitledMultiSelectEnumSchema wire "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'description' => 1],
            'UntitledMultiSelectEnumSchema wire "description" must be a string or null, int given.',
        ];

        yield 'minItems not an int' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'minItems' => 'x'],
            'UntitledMultiSelectEnumSchema wire "minItems" must be an int or null, string given.',
        ];

        yield 'maxItems not an int' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'maxItems' => 'x'],
            'UntitledMultiSelectEnumSchema wire "maxItems" must be an int or null, string given.',
        ];

        yield 'default not a list' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'default' => ['k' => 'v']],
            'UntitledMultiSelectEnumSchema wire "default" must be a list, got non-list array.',
        ];

        yield 'default entry not a string' => [
            ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a']], 'default' => [1]],
            'UntitledMultiSelectEnumSchema wire default entry must be a string, int given.',
        ];
    }
}
