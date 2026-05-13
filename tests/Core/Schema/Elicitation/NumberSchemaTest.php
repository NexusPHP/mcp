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

        self::assertSame('number', $schema->type);
        self::assertNull($schema->title);
        self::assertNull($schema->description);
        self::assertNull($schema->minimum);
        self::assertNull($schema->maximum);
        self::assertNull($schema->default);
    }

    public function testConstructionAcceptsIntegerType(): void
    {
        $schema = new NumberSchema('integer');

        self::assertSame('integer', $schema->type);
    }

    public function testToArrayMinimal(): void
    {
        self::assertSame(['type' => 'number'], new NumberSchema()->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $schema = new NumberSchema('integer', 'Age', 'User age', 0, 120, 30);

        self::assertSame(
            [
                'type' => 'integer',
                'title' => 'Age',
                'description' => 'User age',
                'minimum' => 0,
                'maximum' => 120,
                'default' => 30,
            ],
            $schema->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $schema = new NumberSchema('number', 'x', null, 1);

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $schema = NumberSchema::fromArray([
            'type' => 'integer',
            'title' => 'Age',
            'description' => 'User age',
            'minimum' => 0,
            'maximum' => 120,
            'default' => 30,
        ]);

        self::assertSame('integer', $schema->type);
        self::assertSame(0, $schema->minimum);
        self::assertSame(120, $schema->maximum);
        self::assertSame(30, $schema->default);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new NumberSchema('integer', 'Age', 'desc', 0, 120, 30);

        $rebuilt = NumberSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsUnknownType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('NumberSchema type must be one of "number", "integer".');

        new NumberSchema('string');
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('NumberSchema title must be a non-empty string or null.');

        new NumberSchema('number', '');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('NumberSchema description must be a non-empty string or null.');

        new NumberSchema('number', null, '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        NumberSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            [],
            'NumberSchema data missing "type".',
        ];

        yield 'type not a string' => [
            ['type' => 1],
            'NumberSchema "type" must be a string, int given.',
        ];

        yield 'unknown type' => [
            ['type' => 'string'],
            'NumberSchema type must be one of "number", "integer".',
        ];

        yield 'title not a string' => [
            ['type' => 'number', 'title' => 1],
            'NumberSchema "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'number', 'description' => 1],
            'NumberSchema "description" must be a string or null, int given.',
        ];

        yield 'minimum not an int' => [
            ['type' => 'number', 'minimum' => 'x'],
            'NumberSchema "minimum" must be an int or null, string given.',
        ];

        yield 'maximum not an int' => [
            ['type' => 'number', 'maximum' => 'x'],
            'NumberSchema "maximum" must be an int or null, string given.',
        ];

        yield 'default not an int' => [
            ['type' => 'number', 'default' => 'x'],
            'NumberSchema "default" must be an int or null, string given.',
        ];
    }
}
