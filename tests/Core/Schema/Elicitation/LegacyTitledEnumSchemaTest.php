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
use Nexus\Mcp\Core\Schema\Elicitation\LegacyTitledEnumSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(LegacyTitledEnumSchema::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class LegacyTitledEnumSchemaTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $schema = new LegacyTitledEnumSchema(['a', 'b']);

        self::assertSame(['a', 'b'], $schema->enum);
        self::assertNull($schema->title);
        self::assertNull($schema->description);
        self::assertNull($schema->enumNames);
        self::assertNull($schema->default);
    }

    public function testToArrayMinimal(): void
    {
        $schema = new LegacyTitledEnumSchema(['a']);

        self::assertSame(['type' => 'string', 'enum' => ['a']], $schema->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $schema = new LegacyTitledEnumSchema(['S', 'M'], 'Size', 'desc', ['Small', 'Medium'], 'M');

        self::assertSame(
            [
                'type' => 'string',
                'enum' => ['S', 'M'],
                'title' => 'Size',
                'description' => 'desc',
                'enumNames' => ['Small', 'Medium'],
                'default' => 'M',
            ],
            $schema->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $schema = new LegacyTitledEnumSchema(['a'], 'T');

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new LegacyTitledEnumSchema(['S', 'M'], 'Size', 'desc', ['Small', 'Medium'], 'M');

        $rebuilt = LegacyTitledEnumSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListEnum(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('LegacyTitledEnumSchema enum must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new LegacyTitledEnumSchema(['k' => 'v']);
    }

    public function testConstructorRejectsEmptyEnumEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('LegacyTitledEnumSchema enum entry must be a non-empty string.');

        new LegacyTitledEnumSchema(['']);
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('LegacyTitledEnumSchema title must be a non-empty string or null.');

        new LegacyTitledEnumSchema(['a'], '');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('LegacyTitledEnumSchema description must be a non-empty string or null.');

        new LegacyTitledEnumSchema(['a'], null, '');
    }

    public function testConstructorRejectsNonListEnumNames(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('LegacyTitledEnumSchema enumNames must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new LegacyTitledEnumSchema(['a'], null, null, ['k' => 'v']);
    }

    public function testConstructorRejectsEmptyEnumNamesEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('LegacyTitledEnumSchema enumNames entry must be a non-empty string.');

        new LegacyTitledEnumSchema(['a'], null, null, ['']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        LegacyTitledEnumSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing type' => [
            ['enum' => ['a']],
            'LegacyTitledEnumSchema wire data missing "type".',
        ];

        yield 'wrong type literal' => [
            ['type' => 'number', 'enum' => ['a']],
            'LegacyTitledEnumSchema wire "type" must be "string", \'number\' given.',
        ];

        yield 'missing enum' => [
            ['type' => 'string'],
            'LegacyTitledEnumSchema wire data missing "enum".',
        ];

        yield 'enum not a list' => [
            ['type' => 'string', 'enum' => ['k' => 'v']],
            'LegacyTitledEnumSchema wire "enum" must be a list, got non-list array.',
        ];

        yield 'enum entry not a string' => [
            ['type' => 'string', 'enum' => [1]],
            'LegacyTitledEnumSchema wire "enum" entry must be a string, int given.',
        ];

        yield 'title not a string' => [
            ['type' => 'string', 'enum' => ['a'], 'title' => 1],
            'LegacyTitledEnumSchema wire "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'string', 'enum' => ['a'], 'description' => 1],
            'LegacyTitledEnumSchema wire "description" must be a string or null, int given.',
        ];

        yield 'enumNames not a list' => [
            ['type' => 'string', 'enum' => ['a'], 'enumNames' => ['k' => 'v']],
            'LegacyTitledEnumSchema wire "enumNames" must be a list, got non-list array.',
        ];

        yield 'enumNames entry not a string' => [
            ['type' => 'string', 'enum' => ['a'], 'enumNames' => [1]],
            'LegacyTitledEnumSchema wire enumNames entry must be a string, int given.',
        ];

        yield 'default not a string' => [
            ['type' => 'string', 'enum' => ['a'], 'default' => 1],
            'LegacyTitledEnumSchema wire "default" must be a string or null, int given.',
        ];
    }
}
