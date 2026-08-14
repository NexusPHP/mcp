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

use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Server\Discovery\InputSchemaGenerator;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\AbstractShape;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedStringEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\Coordinate;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\Place;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\SampleToolHandlers;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ShapeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(InputSchemaGenerator::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class InputSchemaGeneratorTest extends AbstractMcpTestCase
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

    public function testVariadicParameterBecomesAnArray(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ], self::generate('variadicStrings'));
    }

    public function testVariadicParameterCarriesItsDocblockDescription(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => [
                'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'A label to apply.'],
            ],
        ], self::generate('variadicDescribed'));
    }

    public function testUntypedVariadicParameterIsAnUntypedArray(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['values' => ['type' => 'array']],
        ], self::generate('variadicUntyped'));
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

    public function testParameterExplicitTypeReplacesInferredSchema(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['color' => ['type' => 'string']],
            'required' => ['color'],
        ], self::generate('explicitType'));
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

    public function testMethodConstraintsMergeOverTheInferredSchema(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['unit' => ['type' => 'string']],
            'required' => ['unit'],
            'description' => 'MY METHOD DESCRIPTION',
            'additionalProperties' => false,
        ], self::generate('methodConstraints'));
    }

    public function testAMethodPropertiesOverrideDiscardsTheInferredRequiredList(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['other' => ['type' => 'integer']],
        ], self::generate('methodPropertiesOverride'));
    }

    public function testUnsupportedParameterTypeThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/parameter "\$when".+SampleToolHandlers::unsupported/');

        self::generate('unsupported');
    }

    public function testBareArrayWithUnmappableDocblockFallsBackToArray(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['items' => ['type' => 'array']],
            'required' => ['items'],
        ], self::generate('unmappableArrayDoc'));
    }

    public function testUntypedParameterYieldsTheAlwaysValidSchema(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['anything' => true],
            'required' => ['anything'],
        ], self::generate('untyped'));
    }

    public function testMixedParameterYieldsTheAlwaysValidSchema(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['value' => true],
            'required' => ['value'],
        ], self::generate('mixedParameter'));
    }

    public function testMixedNestedInAShapeYieldsTheAlwaysValidSchema(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => [
                'entry' => [
                    'type' => 'object',
                    'properties' => ['label' => ['type' => 'string'], 'payload' => true],
                    'required' => ['label', 'payload'],
                ],
            ],
            'required' => ['entry'],
        ], self::generate('mixedInsideAShape'));
    }

    public function testMixedNestedInATupleYieldsTheAlwaysValidSchema(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => [
                'pair' => [
                    'type' => 'array',
                    'prefixItems' => [true, ['type' => 'string']],
                    'items' => false,
                    'minItems' => 2,
                ],
            ],
            'required' => ['pair'],
        ], self::generate('mixedInsideATuple'));
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

    public function testClassParameterExpandsToAnObject(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => [
                'point' => [
                    'type' => 'object',
                    'properties' => [
                        'latitude' => ['type' => 'number'],
                        'longitude' => ['type' => 'number'],
                        'label' => ['type' => 'string', 'enum' => ['a', 'b'], 'default' => 'a'],
                    ],
                    'required' => ['latitude', 'longitude'],
                ],
            ],
            'required' => ['point'],
        ], self::generate('geoPoint'));
    }

    public function testClassWithoutConstructorExpandsToAnEmptyObject(): void
    {
        self::assertSame([
            'type' => 'object',
            '$schema' => self::DIALECT,
            'properties' => ['thing' => ['type' => 'object']],
            'required' => ['thing'],
        ], self::generate('noConstructorObject'));
    }

    public function testNestedClassParameterThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/parameter "\$at".+Place::__construct/');

        self::generate('nestedObject');
    }

    public function testAbstractClassParameterThrows(): void
    {
        $this->expectException(LogicException::class);

        self::generate('abstractObject');
    }

    public function testInterfaceParameterThrows(): void
    {
        $this->expectException(LogicException::class);

        self::generate('interfaceObject');
    }

    #[DataProvider('provideIsExpandableCases')]
    public function testIsExpandable(string $class, bool $expected): void
    {
        self::assertSame($expected, InputSchemaGenerator::isExpandable($class));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideIsExpandableCases(): iterable
    {
        yield 'instantiable userland class' => [Coordinate::class, true];

        yield 'abstract class' => [AbstractShape::class, false];

        yield 'interface' => [ShapeInterface::class, false];

        yield 'enum' => [BackedStringEnum::class, false];

        yield 'internal class' => [\stdClass::class, false];

        yield 'unknown class' => ['Nexus\\Mcp\\Does\\Not\\Exist', false];
    }

    public function testIsInjectedContextIdentifiesTheServerContextParameter(): void
    {
        self::assertFalse(InputSchemaGenerator::isInjectedContext(self::parameterAt('withContext', 0)));
        self::assertTrue(InputSchemaGenerator::isInjectedContext(self::parameterAt('withContext', 1)));
    }

    public function testResolveExpandableNativeClassAnswersOnlyForAnInstantiableClass(): void
    {
        self::assertSame(
            Place::class,
            InputSchemaGenerator::resolveExpandableNativeClass(self::parameterAt('nestedObject', 0)),
        );
        self::assertNull(
            InputSchemaGenerator::resolveExpandableNativeClass(self::parameterAt('withContext', 0)),
        );
    }

    private static function parameterAt(string $method, int $position): \ReflectionParameter
    {
        $parameters = (new \ReflectionMethod(SampleToolHandlers::class, $method))->getParameters();

        if (! \array_key_exists($position, $parameters)) {
            self::fail(\sprintf('%s() has no parameter at position %d.', $method, $position));
        }

        return $parameters[$position];
    }

    /**
     * @return array<string, mixed>
     */
    private static function generate(string $method): array
    {
        return (new InputSchemaGenerator())->generate(new \ReflectionMethod(SampleToolHandlers::class, $method));
    }
}
