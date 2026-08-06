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
use Nexus\Mcp\Core\Schema\Elicitation\BooleanSchema;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\EnumOption;
use Nexus\Mcp\Core\Schema\Elicitation\LegacyTitledEnumSchema;
use Nexus\Mcp\Core\Schema\Elicitation\NumberSchema;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\Elicitation\TitledMultiSelectEnumSchema;
use Nexus\Mcp\Core\Schema\Elicitation\TitledSingleSelectEnumSchema;
use Nexus\Mcp\Core\Schema\Elicitation\UntitledMultiSelectEnumSchema;
use Nexus\Mcp\Core\Schema\Elicitation\UntitledSingleSelectEnumSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ElicitRequestedSchema::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ElicitRequestedSchemaTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $schema = new ElicitRequestedSchema(properties: ['email' => new StringSchema()]);

        self::assertArrayHasKey('email', $schema->properties);
        self::assertNull($schema->required);
        self::assertNull($schema->schema);
    }

    public function testToArrayMinimal(): void
    {
        $schema = new ElicitRequestedSchema(properties: ['email' => new StringSchema(format: 'email')]);

        self::assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                ],
            ],
            $schema->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $schema = new ElicitRequestedSchema(
            properties: [
                'email' => new StringSchema(format: 'email'),
                'age' => new NumberSchema(type: 'integer'),
            ],
            required: ['email'],
            schema: 'https://json-schema.org/draft/2020-12/schema',
        );

        self::assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'age' => ['type' => 'integer'],
                ],
                'required' => ['email'],
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            ],
            $schema->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $schema = new ElicitRequestedSchema(properties: ['email' => new StringSchema()]);

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayParsesBooleanProperty(): void
    {
        $schema = ElicitRequestedSchema::fromArray([
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean', 'default' => true]],
        ]);

        self::assertArrayHasKey('ok', $schema->properties);
        self::assertInstanceOf(BooleanSchema::class, $schema->properties['ok']);
        self::assertTrue($schema->properties['ok']->default);
    }

    public function testFromArrayParsesNumberPropertyForBothTypeValues(): void
    {
        $schema = ElicitRequestedSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'number'],
                'qty' => ['type' => 'integer'],
            ],
        ]);

        self::assertArrayHasKey('count', $schema->properties);
        self::assertArrayHasKey('qty', $schema->properties);
        self::assertInstanceOf(NumberSchema::class, $schema->properties['count']);
        self::assertSame('number', $schema->properties['count']->type);
        self::assertInstanceOf(NumberSchema::class, $schema->properties['qty']);
        self::assertSame('integer', $schema->properties['qty']->type);
    }

    public function testFromArrayParsesStringPropertyVariants(): void
    {
        $schema = ElicitRequestedSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'free' => ['type' => 'string'],
                'enumVal' => ['type' => 'string', 'enum' => ['a', 'b']],
                'titled' => ['type' => 'string', 'oneOf' => [['const' => 'a', 'title' => 'A']]],
                'legacy' => ['type' => 'string', 'enum' => ['a'], 'enumNames' => ['Apple']],
            ],
        ]);

        self::assertArrayHasKey('free', $schema->properties);
        self::assertArrayHasKey('enumVal', $schema->properties);
        self::assertArrayHasKey('titled', $schema->properties);
        self::assertArrayHasKey('legacy', $schema->properties);
        self::assertInstanceOf(StringSchema::class, $schema->properties['free']);
        self::assertInstanceOf(UntitledSingleSelectEnumSchema::class, $schema->properties['enumVal']);
        self::assertInstanceOf(TitledSingleSelectEnumSchema::class, $schema->properties['titled']);
        self::assertInstanceOf(LegacyTitledEnumSchema::class, $schema->properties['legacy']);
    }

    public function testFromArrayParsesArrayPropertyVariants(): void
    {
        $schema = ElicitRequestedSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'untitled' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['a']],
                ],
                'titled' => [
                    'type' => 'array',
                    'items' => ['anyOf' => [['const' => 'a', 'title' => 'A']]],
                ],
            ],
        ]);

        self::assertArrayHasKey('untitled', $schema->properties);
        self::assertArrayHasKey('titled', $schema->properties);
        self::assertInstanceOf(UntitledMultiSelectEnumSchema::class, $schema->properties['untitled']);
        self::assertInstanceOf(TitledMultiSelectEnumSchema::class, $schema->properties['titled']);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ElicitRequestedSchema(
            properties: [
                'email' => new StringSchema(format: 'email'),
                'plan' => new TitledSingleSelectEnumSchema(oneOf: [new EnumOption(const: 'free', title: 'Free')]),
            ],
            required: ['email'],
            schema: 'https://json-schema.org/draft/2020-12/schema',
        );

        $rebuilt = ElicitRequestedSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsListKeyedProperties(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"requestedSchema.properties" must be a string-keyed map.');

        // @phpstan-ignore argument.type
        new ElicitRequestedSchema(properties: [new StringSchema()]);
    }

    public function testConstructorRejectsEmptyPropertyName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "requestedSchema.properties" key must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ElicitRequestedSchema(properties: ['' => new StringSchema()]);
    }

    public function testConstructorRejectsNonPrimitivePropertyValue(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new ElicitRequestedSchema(properties: ['x' => 42]);
    }

    public function testConstructorRejectsNonListRequired(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"requestedSchema.required" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new ElicitRequestedSchema(properties: ['x' => new StringSchema()], required: ['k' => 'v']);
    }

    public function testConstructorRejectsEmptyRequiredEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "requestedSchema.required" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ElicitRequestedSchema(properties: ['x' => new StringSchema()], required: ['']);
    }

    public function testConstructorRejectsEmptySchema(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"requestedSchema.$schema" must be a non-empty string or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ElicitRequestedSchema(properties: ['x' => new StringSchema()], schema: '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ElicitRequestedSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            ['properties' => []],
            '"requestedSchema" is missing the required "type" key.',
        ];

        yield 'wrong type literal' => [
            ['type' => 'array', 'properties' => []],
            '"requestedSchema.type" must be \'object\', \'array\' given.',
        ];

        yield 'missing properties' => [
            ['type' => 'object'],
            '"requestedSchema" is missing the required "properties" key.',
        ];

        yield 'properties not an object' => [
            ['type' => 'object', 'properties' => 'oops'],
            '"requestedSchema.properties" must be an object, string given.',
        ];

        yield 'properties list-keyed' => [
            ['type' => 'object', 'properties' => ['oops']],
            '"requestedSchema.properties" must be a string-keyed object.',
        ];

        yield 'property entry not an object' => [
            ['type' => 'object', 'properties' => ['x' => 'oops']],
            '"requestedSchema.properties" must be an object, string given.',
        ];

        yield 'property entry missing type' => [
            ['type' => 'object', 'properties' => ['x' => []]],
            '"requestedSchema.primitiveSchema" must carry a "type" string, null given.',
        ];

        yield 'property entry unknown type' => [
            ['type' => 'object', 'properties' => ['x' => ['type' => 'object']]],
            '"requestedSchema.primitiveSchema" has unknown "type" \'object\'.',
        ];

        yield 'multi-select items not an object' => [
            ['type' => 'object', 'properties' => ['x' => ['type' => 'array', 'items' => 'oops']]],
            '"requestedSchema.items" must be an object, string given.',
        ];

        yield 'multi-select items list-keyed' => [
            ['type' => 'object', 'properties' => ['x' => ['type' => 'array', 'items' => ['string']]]],
            '"requestedSchema" multi-select "items" must be a string-keyed object.',
        ];

        yield 'required not a list' => [
            ['type' => 'object', 'properties' => [], 'required' => ['k' => 'v']],
            '"requestedSchema.required" must be a list, non-list array given.',
        ];

        yield 'required entry not a string' => [
            ['type' => 'object', 'properties' => [], 'required' => [1]],
            'each "requestedSchema.required" must be a non-empty string, int given.',
        ];

        yield '$schema not a string' => [
            ['type' => 'object', 'properties' => [], '$schema' => 1],
            '"requestedSchema.$schema" must be a non-empty string or null, int given.',
        ];
    }
}
