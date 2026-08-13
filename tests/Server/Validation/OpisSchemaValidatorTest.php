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

namespace Nexus\Mcp\Tests\Server\Validation;

use Nexus\Mcp\Server\Validation\OpisSchemaValidator;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(OpisSchemaValidator::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class OpisSchemaValidatorTest extends AbstractMcpTestCase
{
    private const array SCHEMA = [
        'type' => 'object',
        'properties' => ['n' => ['type' => 'integer']],
        'required' => ['n'],
    ];

    public function testConformingDataReturnsNoErrors(): void
    {
        self::assertSame([], (new OpisSchemaValidator())->validate(['n' => 42], self::SCHEMA));
    }

    public function testNonConformingDataReturnsErrorMessages(): void
    {
        self::assertSame(
            ['"n" must be an integer, string given.'],
            (new OpisSchemaValidator())->validate(['n' => 'not-an-int'], self::SCHEMA),
        );
    }

    public function testMissingRequiredPropertyReturnsErrorMessages(): void
    {
        self::assertSame(
            ['missing the required "n" key.'],
            (new OpisSchemaValidator())->validate(['other' => 1], self::SCHEMA),
        );
    }

    public function testReportsEveryViolationNotJustTheFirst(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']],
            'required' => ['a', 'b'],
        ];

        $errors = (new OpisSchemaValidator())->validate(['a' => 1, 'b' => 'x'], $schema);

        self::assertSame(
            ['"a" must be a string, int given.', '"b" must be an integer, string given.'],
            $errors,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('provideAnEmptySubSchemaValidatesAsAlwaysTrueCases')]
    public function testAnEmptySubSchemaValidatesAsAlwaysTrue(array $schema, mixed $data): void
    {
        self::assertSame([], (new OpisSchemaValidator())->validate($data, $schema));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, mixed}>
     */
    public static function provideAnEmptySubSchemaValidatesAsAlwaysTrueCases(): iterable
    {
        yield 'additionalProperties' => [['type' => 'object', 'additionalProperties' => []], ['a' => 1]];

        yield 'contains' => [['type' => 'array', 'contains' => []], [1]];

        yield 'else' => [['if' => ['type' => 'integer'], 'else' => []], 'x'];

        yield 'if' => [['if' => [], 'then' => ['type' => 'string']], 'x'];

        yield 'items' => [['type' => 'array', 'items' => []], [1]];

        yield 'propertyNames' => [['type' => 'object', 'propertyNames' => []], ['a' => 1]];

        yield 'then' => [['if' => ['type' => 'string'], 'then' => []], 'x'];

        yield 'unevaluatedItems' => [['type' => 'array', 'unevaluatedItems' => []], [1]];

        yield 'unevaluatedProperties' => [['type' => 'object', 'unevaluatedProperties' => []], ['a' => 1]];

        yield '$defs value reached through $ref' => [
            ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/$defs/x']], '$defs' => ['x' => []]],
            ['a' => 1],
        ];

        yield 'dependentSchemas value' => [['type' => 'object', 'dependentSchemas' => ['a' => []]], ['a' => 1]];

        yield 'patternProperties value' => [['type' => 'object', 'patternProperties' => ['^a' => []]], ['a' => 1]];

        yield 'properties value' => [['type' => 'object', 'properties' => ['extra' => []]], ['extra' => 'anything']];

        yield 'empty dependentSchemas map' => [['type' => 'object', 'dependentSchemas' => []], ['a' => 1]];

        yield 'empty patternProperties map' => [['type' => 'object', 'patternProperties' => []], ['a' => 1]];

        yield 'empty properties map' => [['type' => 'object', 'properties' => []], ['a' => 1]];

        yield 'empty map followed by a later map keyword' => [
            ['type' => 'object', 'dependentSchemas' => [], 'properties' => ['extra' => []]],
            ['extra' => 'anything'],
        ];

        yield 'allOf element' => [['allOf' => [[]]], 'x'];

        yield 'anyOf element' => [['anyOf' => [['type' => 'integer'], []]], 'x'];

        yield 'oneOf element' => [['oneOf' => [['type' => 'integer'], []]], 'x'];

        yield 'prefixItems element' => [['type' => 'array', 'prefixItems' => [[]]], ['anything']];

        yield 'nested empty sub-schema' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'properties' => ['b' => []]]]],
            ['a' => ['b' => 1]],
        ];

        yield 'empty sub-schema under a single-schema keyword' => [
            ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['b' => []]]],
            [['b' => 1]],
        ];
    }

    public function testAnEmptyNotSubSchemaRejectsEveryValue(): void
    {
        self::assertNotSame([], (new OpisSchemaValidator())->validate('x', ['not' => []]));
    }

    public function testAnEmptyRequiredListStaysAList(): void
    {
        self::assertSame([], (new OpisSchemaValidator())->validate(['a' => 1], ['type' => 'object', 'required' => []]));
    }

    public function testAnEmptyConstStaysAnEmptyArrayValue(): void
    {
        self::assertSame([], (new OpisSchemaValidator())->validate([], ['const' => []]));
    }
}
