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
use Nexus\Mcp\Core\Schema\Elicitation\UntitledSingleSelectEnumSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UntitledSingleSelectEnumSchema::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class UntitledSingleSelectEnumSchemaTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $schema = new UntitledSingleSelectEnumSchema(['a', 'b']);

        self::assertSame(['a', 'b'], $schema->enum);
        self::assertNull($schema->title);
        self::assertNull($schema->description);
        self::assertNull($schema->default);
    }

    public function testToArrayMinimal(): void
    {
        $schema = new UntitledSingleSelectEnumSchema(['a']);

        self::assertSame(['type' => 'string', 'enum' => ['a']], $schema->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $schema = new UntitledSingleSelectEnumSchema(['red', 'blue'], 'Colour', 'Pick one', 'blue');

        self::assertSame(
            [
                'type' => 'string',
                'enum' => ['red', 'blue'],
                'title' => 'Colour',
                'description' => 'Pick one',
                'default' => 'blue',
            ],
            $schema->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $schema = new UntitledSingleSelectEnumSchema(['a'], 'T', null, 'a');

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new UntitledSingleSelectEnumSchema(['x', 'y'], 'T', 'desc', 'y');

        $rebuilt = UntitledSingleSelectEnumSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListEnum(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UntitledSingleSelectEnumSchema enum must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new UntitledSingleSelectEnumSchema(['k' => 'v']);
    }

    public function testConstructorRejectsEmptyEnumEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UntitledSingleSelectEnumSchema enum entry must be a non-empty string.');

        new UntitledSingleSelectEnumSchema(['']);
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UntitledSingleSelectEnumSchema title must be a non-empty string or null.');

        new UntitledSingleSelectEnumSchema(['a'], '');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('UntitledSingleSelectEnumSchema description must be a non-empty string or null.');

        new UntitledSingleSelectEnumSchema(['a'], null, '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        UntitledSingleSelectEnumSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            ['enum' => ['a']],
            'UntitledSingleSelectEnumSchema data missing "type".',
        ];

        yield 'wrong type literal' => [
            ['type' => 'number', 'enum' => ['a']],
            'UntitledSingleSelectEnumSchema "type" must be "string", \'number\' given.',
        ];

        yield 'missing enum' => [
            ['type' => 'string'],
            'UntitledSingleSelectEnumSchema data missing "enum".',
        ];

        yield 'enum not a list' => [
            ['type' => 'string', 'enum' => ['k' => 'v']],
            'UntitledSingleSelectEnumSchema "enum" must be a list, got non-list array.',
        ];

        yield 'enum entry not a string' => [
            ['type' => 'string', 'enum' => [1]],
            'UntitledSingleSelectEnumSchema "enum" entry must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['type' => 'string', 'enum' => ['a'], 'title' => 1],
            'UntitledSingleSelectEnumSchema "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'string', 'enum' => ['a'], 'description' => 1],
            'UntitledSingleSelectEnumSchema "description" must be a string or null, int given.',
        ];

        yield 'default not a string' => [
            ['type' => 'string', 'enum' => ['a'], 'default' => 1],
            'UntitledSingleSelectEnumSchema "default" must be a string or null, int given.',
        ];
    }
}
