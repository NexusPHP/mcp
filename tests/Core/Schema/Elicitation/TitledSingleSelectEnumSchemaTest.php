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
use Nexus\Mcp\Core\Schema\Elicitation\TitledSingleSelectEnumSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TitledSingleSelectEnumSchema::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TitledSingleSelectEnumSchemaTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $opt = new EnumOption('a', 'Apple');
        $schema = new TitledSingleSelectEnumSchema([$opt]);

        self::assertCount(1, $schema->oneOf);
        self::assertSame('a', $schema->oneOf[0]->const);
    }

    public function testToArrayMinimal(): void
    {
        $schema = new TitledSingleSelectEnumSchema([new EnumOption('a', 'Apple')]);

        self::assertSame(
            [
                'type' => 'string',
                'oneOf' => [['const' => 'a', 'title' => 'Apple']],
            ],
            $schema->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $schema = new TitledSingleSelectEnumSchema(
            [new EnumOption('a', 'Apple'), new EnumOption('b', 'Banana')],
            'Fruit',
            'Pick a fruit.',
            'a',
        );

        self::assertSame(
            [
                'type' => 'string',
                'oneOf' => [
                    ['const' => 'a', 'title' => 'Apple'],
                    ['const' => 'b', 'title' => 'Banana'],
                ],
                'title' => 'Fruit',
                'description' => 'Pick a fruit.',
                'default' => 'a',
            ],
            $schema->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $schema = new TitledSingleSelectEnumSchema([new EnumOption('a', 'A')]);

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new TitledSingleSelectEnumSchema(
            [new EnumOption('a', 'Apple')],
            'Fruit',
            'desc',
            'a',
        );

        $rebuilt = TitledSingleSelectEnumSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListOneOf(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('TitledSingleSelectEnumSchema oneOf must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new TitledSingleSelectEnumSchema(['k' => new EnumOption('a', 'A')]);
    }

    public function testConstructorRejectsNonEnumOptionEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new TitledSingleSelectEnumSchema([42]);
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('TitledSingleSelectEnumSchema title must be a non-empty string or null.');

        new TitledSingleSelectEnumSchema([new EnumOption('a', 'A')], '');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('TitledSingleSelectEnumSchema description must be a non-empty string or null.');

        new TitledSingleSelectEnumSchema([new EnumOption('a', 'A')], null, '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        TitledSingleSelectEnumSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            ['oneOf' => []],
            'TitledSingleSelectEnumSchema data missing "type".',
        ];

        yield 'wrong type literal' => [
            ['type' => 'number', 'oneOf' => []],
            'TitledSingleSelectEnumSchema "type" must be "string", \'number\' given.',
        ];

        yield 'missing oneOf' => [
            ['type' => 'string'],
            'TitledSingleSelectEnumSchema data missing "oneOf".',
        ];

        yield 'oneOf not a list' => [
            ['type' => 'string', 'oneOf' => ['k' => []]],
            'TitledSingleSelectEnumSchema "oneOf" must be a list, got non-list array.',
        ];

        yield 'oneOf entry not an object' => [
            ['type' => 'string', 'oneOf' => ['oops']],
            'TitledSingleSelectEnumSchema oneOf entry must be an object, string given.',
        ];

        yield 'oneOf entry list-keyed' => [
            ['type' => 'string', 'oneOf' => [['x']]],
            'TitledSingleSelectEnumSchema oneOf entry must be a string-keyed object.',
        ];

        yield 'title not a string' => [
            ['type' => 'string', 'oneOf' => [], 'title' => 1],
            'TitledSingleSelectEnumSchema "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'string', 'oneOf' => [], 'description' => 1],
            'TitledSingleSelectEnumSchema "description" must be a string or null, int given.',
        ];

        yield 'default not a string' => [
            ['type' => 'string', 'oneOf' => [], 'default' => 1],
            'TitledSingleSelectEnumSchema "default" must be a string or null, int given.',
        ];
    }
}
