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
use Nexus\Mcp\Core\Schema\Elicitation\NumberSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(NumberSchema::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class NumberSchemaTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $schema = new NumberSchema();

        self::assertSame(NumberSchema::TYPE, $schema->type);
        self::assertNull($schema->title);
        self::assertNull($schema->description);
        self::assertNull($schema->minimum);
        self::assertNull($schema->maximum);
        self::assertNull($schema->default);
    }

    public function testConstructionAcceptsIntegerType(): void
    {
        $schema = new NumberSchema(type: NumberSchema::TYPE_INTEGER);

        self::assertSame(NumberSchema::TYPE_INTEGER, $schema->type);
    }

    public function testToArrayMinimal(): void
    {
        self::assertSame(['type' => NumberSchema::TYPE], new NumberSchema()->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $schema = new NumberSchema(
            type: NumberSchema::TYPE,
            title: 'Rating',
            description: 'User rating',
            minimum: 0.5,
            maximum: 9.9,
            default: 1.5,
        );

        self::assertSame(
            [
                'type' => NumberSchema::TYPE,
                'title' => 'Rating',
                'description' => 'User rating',
                'minimum' => 0.5,
                'maximum' => 9.9,
                'default' => 1.5,
            ],
            $schema->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $schema = new NumberSchema(title: 'x', minimum: 1.0);

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayParsesFloatBounds(): void
    {
        $schema = NumberSchema::fromArray([
            'type' => NumberSchema::TYPE,
            'title' => 'Rating',
            'description' => 'User rating',
            'minimum' => 0.5,
            'maximum' => 9.9,
            'default' => 1.5,
        ]);

        self::assertSame(NumberSchema::TYPE, $schema->type);
        self::assertSame(0.5, $schema->minimum);
        self::assertSame(9.9, $schema->maximum);
        self::assertSame(1.5, $schema->default);
    }

    public function testFromArrayCoercesIntegerBoundsToFloat(): void
    {
        $schema = NumberSchema::fromArray([
            'type' => NumberSchema::TYPE_INTEGER,
            'title' => 'Age',
            'description' => 'User age',
            'minimum' => 0,
            'maximum' => 120,
            'default' => 30,
        ]);

        self::assertSame(NumberSchema::TYPE_INTEGER, $schema->type);
        self::assertSame(0.0, $schema->minimum);
        self::assertSame(120.0, $schema->maximum);
        self::assertSame(30.0, $schema->default);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new NumberSchema(
            type: NumberSchema::TYPE_INTEGER,
            title: 'Rating',
            description: 'desc',
            minimum: 0.5,
            maximum: 9.9,
            default: 1.5,
        );

        $rebuilt = NumberSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsUnknownType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('number schema "type" must be one of [\'number\', \'integer\'].');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new NumberSchema(type: 'string');
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('number schema "title" must be a non-empty string or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new NumberSchema(title: '');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('number schema "description" must be a non-empty string or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new NumberSchema(description: '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        NumberSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            [],
            'number schema is missing the required "type" key.',
        ];

        yield 'type not a string' => [
            ['type' => 1],
            'number schema "type" must be one of [\'number\', \'integer\'], 1 given.',
        ];

        yield 'unknown type' => [
            ['type' => 'string'],
            'number schema "type" must be one of [\'number\', \'integer\'], \'string\' given.',
        ];

        yield 'title not a string' => [
            ['type' => 'number', 'title' => 1],
            'number schema "title" must be a non-empty string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'number', 'description' => 1],
            'number schema "description" must be a non-empty string or null, int given.',
        ];

        yield 'minimum not a number' => [
            ['type' => 'number', 'minimum' => 'x'],
            'number schema "minimum" must be a number or null, string given.',
        ];

        yield 'maximum not a number' => [
            ['type' => 'number', 'maximum' => 'x'],
            'number schema "maximum" must be a number or null, string given.',
        ];

        yield 'default not a number' => [
            ['type' => 'number', 'default' => 'x'],
            'number schema "default" must be a number or null, string given.',
        ];
    }
}
