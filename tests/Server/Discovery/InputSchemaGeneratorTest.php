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

namespace Nexus\Mcp\Tests\Server\Discovery;

use Nexus\Mcp\Server\Discovery\InputSchemaGenerator;
use Nexus\Mcp\Server\Exception\SchemaGenerationException;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\SampleToolHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InputSchemaGenerator::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class InputSchemaGeneratorTest extends TestCase
{
    private const string DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    public function testScalarParameters(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
                'active' => ['type' => 'boolean'],
                'score' => ['type' => 'number'],
            ],
            'required' => ['name', 'age', 'active', 'score'],
        ], self::generate('scalars'));
    }

    public function testNoArgumentsOmitsPropertiesAndRequired(): void
    {
        self::assertSame(['type' => 'object', '$schema' => self::DIALECT], self::generate('noArguments'));
    }

    public function testDescriptionComesFromDocblock(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['label' => ['type' => 'string', 'description' => 'A friendly label.']],
            'required' => ['label'],
        ], self::generate('described'));
    }

    public function testOptionalAndNullableParameters(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => [
                'nickname' => ['type' => ['null', 'string'], 'default' => null],
                'count' => ['type' => 'integer', 'default' => 3],
            ],
        ], self::generate('optionalAndNullable'));
    }

    public function testEnumParameters(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => [
                'color' => ['type' => 'string', 'enum' => ['a', 'b']],
                'level' => ['type' => 'integer', 'enum' => [1, 2]],
                'flag' => ['type' => 'string', 'enum' => ['Yes', 'No']],
            ],
            'required' => ['color', 'level', 'flag'],
        ], self::generate('enums'));
    }

    public function testDocblockRefinesArrayParameters(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'owner' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
                    'required' => ['id', 'name'],
                ],
            ],
            'required' => ['tags', 'owner'],
        ], self::generate('collections'));
    }

    public function testLiteralUnionParameterBecomesEnum(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['unit' => ['type' => 'string', 'enum' => ['celsius', 'fahrenheit']]],
            'required' => ['unit'],
        ], self::generate('literalUnion'));
    }

    public function testRefinedScalarParametersCarryConstraints(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => [
                'code' => ['type' => 'string', 'minLength' => 1],
                'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            ],
            'required' => ['code', 'rating'],
        ], self::generate('refined'));
    }

    public function testServerContextParameterIsExcluded(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['query' => ['type' => 'string']],
            'required' => ['query'],
        ], self::generate('withContext'));
    }

    public function testParameterConstraintMergesOverInferred(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['email' => ['type' => 'string', 'format' => 'email', 'minLength' => 3]],
            'required' => ['email'],
        ], self::generate('paramConstraint'));
    }

    public function testParameterDefinitionOverridesInference(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['token' => ['type' => 'string', 'const' => 'fixed']],
            'required' => ['token'],
        ], self::generate('paramDefinition'));
    }

    public function testMethodDefinitionShortCircuitsAndInjectsDialect(): void
    {
        self::assertSame([
            '$schema' => self::DIALECT,
            'type' => 'object',
            'properties' => ['x' => ['type' => 'integer']],
            'required' => ['x'],
        ], self::generate('methodDefinition'));
    }

    public function testUnsupportedParameterTypeThrows(): void
    {
        $this->expectException(SchemaGenerationException::class);
        $this->expectExceptionMessageMatches('/parameter "\$when".+SampleToolHandlers::unsupported/');

        self::generate('unsupported');
    }

    public function testUntypedParameterYieldsEmptySchema(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['anything' => []],
            'required' => ['anything'],
        ], self::generate('untyped'));
    }

    public function testMixedParameterYieldsEmptySchema(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['value' => []],
            'required' => ['value'],
        ], self::generate('mixedParameter'));
    }

    public function testEnumDefaultsAreUnwrapped(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => [
                'color' => ['type' => 'string', 'enum' => ['a', 'b'], 'default' => 'a'],
                'flag' => ['type' => 'string', 'enum' => ['Yes', 'No'], 'default' => 'Yes'],
            ],
        ], self::generate('enumDefaults'));
    }

    public function testInjectedContextBeforeOtherParametersIsExcluded(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['value' => ['type' => 'string']],
            'required' => ['value'],
        ], self::generate('contextNotLast'));
    }

    public function testParameterDefinitionIgnoresDocblockDescription(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['token' => ['type' => 'string', 'const' => 'fixed']],
            'required' => ['token'],
        ], self::generate('definitionWithDocblock'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function generate(string $method): array
    {
        return new InputSchemaGenerator()->generate(new \ReflectionMethod(SampleToolHandlers::class, $method));
    }
}
