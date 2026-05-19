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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BooleanSchema::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class BooleanSchemaTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $schema = new BooleanSchema();

        self::assertNull($schema->title);
        self::assertNull($schema->description);
        self::assertNull($schema->default);
    }

    public function testToArrayMinimal(): void
    {
        $schema = new BooleanSchema();

        self::assertSame(['type' => 'boolean'], $schema->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $schema = new BooleanSchema('Accept', 'Accept the terms.', false);

        self::assertSame(
            [
                'type' => 'boolean',
                'title' => 'Accept',
                'description' => 'Accept the terms.',
                'default' => false,
            ],
            $schema->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $schema = new BooleanSchema('Accept', null, true);

        self::assertSame($schema->toArray(), $schema->jsonSerialize());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $schema = BooleanSchema::fromArray([
            'type' => 'boolean',
            'title' => 'Accept',
            'description' => 'Accept the terms.',
            'default' => true,
        ]);

        self::assertSame('Accept', $schema->title);
        self::assertSame('Accept the terms.', $schema->description);
        self::assertTrue($schema->default);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new BooleanSchema('Accept', 'desc', false);

        $rebuilt = BooleanSchema::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('boolean schema "title" must be a non-empty string or null.');

        new BooleanSchema('');
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('boolean schema "description" must be a non-empty string or null.');

        new BooleanSchema(null, '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        BooleanSchema::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing type' => [
            [],
            'boolean schema missing the required "type" key.',
        ];

        yield 'wrong type literal' => [
            ['type' => 'string'],
            'boolean schema "type" must be \'boolean\', \'string\' given.',
        ];

        yield 'title not a string' => [
            ['type' => 'boolean', 'title' => 1],
            'boolean schema "title" must be a string or null, int given.',
        ];

        yield 'description not a string' => [
            ['type' => 'boolean', 'description' => 1],
            'boolean schema "description" must be a string or null, int given.',
        ];

        yield 'default not a bool' => [
            ['type' => 'boolean', 'default' => 'yes'],
            'boolean schema "default" must be a bool or null, string given.',
        ];
    }
}
