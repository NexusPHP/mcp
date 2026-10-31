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

use Nexus\Mcp\Server\Discovery\DocBlockTypeResolver;
use Nexus\Mcp\Server\Discovery\TypeNodeSchemaMapper;
use Nexus\Mcp\Server\Exception\UnsupportedSchemaTypeException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedIntEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedStringEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\PureEnum;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(TypeNodeSchemaMapper::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class TypeNodeSchemaMapperTest extends AbstractMcpTestCase
{
    private TypeNodeSchemaMapper $mapper;
    private DocBlockTypeResolver $resolver;

    #[\Override]
    protected function setUp(): void
    {
        $this->mapper = new TypeNodeSchemaMapper();
        $this->resolver = new DocBlockTypeResolver();
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('provideMapsTypeStringCases')]
    public function testMapsTypeString(string $type, array $expected): void
    {
        self::assertSame($expected, $this->mapper->map($this->resolver->parseNativeType($type)));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function provideMapsTypeStringCases(): iterable
    {
        yield 'int' => [
            'int',
            ['type' => 'integer'],
        ];

        yield 'integer alias' => [
            'integer',
            ['type' => 'integer'],
        ];

        yield 'float' => [
            'float',
            ['type' => 'number'],
        ];

        yield 'double alias' => [
            'double',
            ['type' => 'number'],
        ];

        yield 'bool' => [
            'bool',
            ['type' => 'boolean'],
        ];

        yield 'boolean alias' => [
            'boolean',
            ['type' => 'boolean'],
        ];

        yield 'string' => [
            'string',
            ['type' => 'string'],
        ];

        yield 'array' => [
            'array',
            ['type' => 'array'],
        ];

        yield 'iterable' => [
            'iterable',
            ['type' => 'array'],
        ];

        yield 'list' => [
            'list',
            ['type' => 'array'],
        ];

        yield 'object' => [
            'object',
            ['type' => 'object'],
        ];

        yield \stdClass::class => [
            \stdClass::class,
            ['type' => 'object'],
        ];

        yield 'mixed' => [
            'mixed',
            [],
        ];

        yield 'null' => [
            'null',
            ['type' => 'null'],
        ];

        yield 'nullable int' => [
            '?int',
            ['type' => ['null', 'integer']],
        ];

        yield 'int or null' => [
            'int|null',
            ['type' => ['null', 'integer']],
        ];

        yield 'null then int' => [
            'null|int',
            ['type' => ['null', 'integer']],
        ];

        yield 'scalar union' => [
            'int|string',
            ['type' => ['integer', 'string']],
        ];

        yield 'scalar union reversed sorts' => [
            'string|int',
            ['type' => ['integer', 'string']],
        ];

        yield 'scalar union with null' => [
            'int|string|null',
            ['type' => ['null', 'integer', 'string']],
        ];

        yield 'duplicate union collapses' => [
            'int|int',
            ['type' => 'integer'],
        ];

        yield 'typed array shorthand' => [
            'string[]',
            ['type' => 'array', 'items' => ['type' => 'string']],
        ];

        yield 'generic array' => [
            'array<int>',
            ['type' => 'array', 'items' => ['type' => 'integer']],
        ];

        yield 'generic list' => [
            'list<string>',
            ['type' => 'array', 'items' => ['type' => 'string']],
        ];

        yield 'generic iterable' => [
            'iterable<bool>',
            ['type' => 'array', 'items' => ['type' => 'boolean']],
        ];

        yield 'untyped element array' => [
            'array<mixed>',
            ['type' => 'array'],
        ];

        yield 'generic array of union' => [
            'array<int|string>',
            ['type' => 'array', 'items' => ['type' => ['integer', 'string']]],
        ];

        yield 'int-keyed map is a list' => [
            'array<int, string>',
            ['type' => 'array', 'items' => ['type' => 'string']],
        ];

        yield 'string-keyed map is an object' => [
            'array<string, int>',
            ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
        ];

        yield 'open map' => [
            'array<string, mixed>',
            ['type' => 'object', 'additionalProperties' => true],
        ];

        yield 'nested array shorthand' => [
            'int[][]',
            ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer']]],
        ];

        yield 'nested generic array' => [
            'array<array<int>>',
            ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer']]],
        ];

        yield 'array shape' => [
            'array{id: int, name: string}',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], 'required' => ['id', 'name']],
        ];

        yield 'array shape optional key' => [
            'array{id: int, name?: string}',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], 'required' => ['id']],
        ];

        yield 'array shape all optional' => [
            'array{id?: int}',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ];

        yield 'nullable typed array keeps items' => [
            'string[]|null',
            ['type' => ['null', 'array'], 'items' => ['type' => 'string']],
        ];

        yield 'backed string enum' => [
            BackedStringEnum::class,
            ['type' => 'string', 'enum' => ['a', 'b']],
        ];

        yield 'backed int enum' => [
            BackedIntEnum::class,
            ['type' => 'integer', 'enum' => [1, 2]],
        ];

        yield 'pure enum' => [
            PureEnum::class,
            ['type' => 'string', 'enum' => ['Yes', 'No']],
        ];

        yield 'nullable backed enum' => [
            '?'.BackedStringEnum::class,
            ['type' => ['null', 'string'], 'enum' => ['a', 'b', null]],
        ];

        yield 'string literal' => [
            '\'a\'',
            ['type' => 'string', 'enum' => ['a']],
        ];

        yield 'string literal union' => [
            '\'a\'|\'b\'',
            ['type' => 'string', 'enum' => ['a', 'b']],
        ];

        yield 'int literal union' => [
            '1|2',
            ['type' => 'integer', 'enum' => [1, 2]],
        ];

        yield 'nullable string literal union' => [
            '\'a\'|\'b\'|null',
            ['type' => ['null', 'string'], 'enum' => ['a', 'b', null]],
        ];

        yield 'non-empty-string' => [
            'non-empty-string',
            ['type' => 'string', 'minLength' => 1],
        ];

        yield 'positive-int' => [
            'positive-int',
            ['type' => 'integer', 'minimum' => 1],
        ];

        yield 'negative-int' => [
            'negative-int',
            ['type' => 'integer', 'maximum' => -1],
        ];

        yield 'non-negative-int' => [
            'non-negative-int',
            ['type' => 'integer', 'minimum' => 0],
        ];

        yield 'non-positive-int' => [
            'non-positive-int',
            ['type' => 'integer', 'maximum' => 0],
        ];

        yield 'nullable non-empty-string keeps constraint' => [
            '?non-empty-string',
            ['type' => ['null', 'string'], 'minLength' => 1],
        ];

        yield 'int range both bounds' => [
            'int<0, 100>',
            ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
        ];

        yield 'int range negative bounds' => [
            'int<-10, -1>',
            ['type' => 'integer', 'minimum' => -10, 'maximum' => -1],
        ];

        yield 'int range min sentinel' => [
            'int<min, 100>',
            ['type' => 'integer', 'maximum' => 100],
        ];

        yield 'int range max sentinel' => [
            'int<50, max>',
            ['type' => 'integer', 'minimum' => 50],
        ];

        yield 'object shape' => [
            'object{id: int, name: string}',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], 'required' => ['id', 'name']],
        ];

        yield 'object shape optional property' => [
            'object{id: int, name?: string}',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], 'required' => ['id']],
        ];

        yield 'tuple array shape' => [
            'array{int, string}',
            ['type' => 'array', 'prefixItems' => [['type' => 'integer'], ['type' => 'string']], 'items' => false, 'minItems' => 2],
        ];

        yield 'tuple list shape' => [
            'list{int, string}',
            ['type' => 'array', 'prefixItems' => [['type' => 'integer'], ['type' => 'string']], 'items' => false, 'minItems' => 2],
        ];

        yield 'single-element tuple' => [
            'array{string}',
            ['type' => 'array', 'prefixItems' => [['type' => 'string']], 'items' => false, 'minItems' => 1],
        ];

        yield 'tuple with optional trailing item' => [
            'list{int, 1?: string}',
            ['type' => 'array', 'prefixItems' => [['type' => 'integer'], ['type' => 'string']], 'items' => false, 'minItems' => 1],
        ];

        yield 'tuple of only optional items' => [
            'list{0?: int}',
            ['type' => 'array', 'prefixItems' => [['type' => 'integer']], 'items' => false],
        ];

        yield 'tuple keyed by position' => [
            'array{0: int, 1: string}',
            ['type' => 'array', 'prefixItems' => [['type' => 'integer'], ['type' => 'string']], 'items' => false, 'minItems' => 2],
        ];

        yield 'unsealed tuple without a type' => [
            'list{int, ...}',
            ['type' => 'array', 'prefixItems' => [['type' => 'integer']], 'minItems' => 1],
        ];

        yield 'unsealed tuple with a type' => [
            'list{int, string, ...<float>}',
            ['type' => 'array', 'prefixItems' => [['type' => 'integer'], ['type' => 'string']], 'items' => ['type' => 'number'], 'minItems' => 2],
        ];

        yield 'unsealed array shape without a type' => [
            'array{a: int, ...}',
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a']],
        ];

        yield 'unsealed array shape with a type' => [
            'array{a: int, ...<string, bool>}',
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a'], 'additionalProperties' => ['type' => 'boolean']],
        ];

        yield 'unsealed array shape with a value type only' => [
            'array{a: int, ...<mixed>}',
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => ['a'], 'additionalProperties' => true],
        ];

        yield 'quoted shape key' => [
            'array{\'a-b\': int, "c": string}',
            ['type' => 'object', 'properties' => ['a-b' => ['type' => 'integer'], 'c' => ['type' => 'string']], 'required' => ['a-b', 'c']],
        ];

        yield 'integer shape key off position' => [
            'array{a: int, 3: string}',
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], '3' => ['type' => 'string']], 'required' => ['a', '3']],
        ];

        yield 'true' => [
            'true',
            ['type' => 'boolean', 'enum' => [true]],
        ];

        yield 'false' => [
            'false',
            ['type' => 'boolean', 'enum' => [false]],
        ];

        yield 'nullable true' => [
            'true|null',
            ['type' => ['null', 'boolean'], 'enum' => [true, null]],
        ];

        yield 'string or false' => [
            'string|false',
            ['anyOf' => [['type' => 'string'], ['type' => 'boolean', 'enum' => [false]]]],
        ];

        yield 'nullable string or false' => [
            'string|false|null',
            ['anyOf' => [['type' => 'string'], ['type' => 'boolean', 'enum' => [false]], ['type' => 'null']]],
        ];

        yield 'true or false' => [
            'true|false',
            ['anyOf' => [['type' => 'boolean', 'enum' => [true]], ['type' => 'boolean', 'enum' => [false]]]],
        ];

        yield 'constrained and bare members' => [
            'non-empty-string|int',
            ['anyOf' => [['type' => 'string', 'minLength' => 1], ['type' => 'integer']]],
        ];

        yield 'scalar or array' => [
            'int|string[]',
            ['anyOf' => [['type' => 'integer'], ['type' => 'array', 'items' => ['type' => 'string']]]],
        ];

        yield 'unconstrained member in a union' => [
            'int|mixed',
            ['anyOf' => [['type' => 'integer'], true]],
        ];

        yield 'literal mixed with general type' => [
            '\'a\'|int',
            ['anyOf' => [['type' => 'string', 'enum' => ['a']], ['type' => 'integer']]],
        ];

        yield 'mixed literal types' => [
            '\'a\'|1',
            ['type' => ['integer', 'string'], 'enum' => ['a', 1]],
        ];

        yield 'float literal' => [
            '1.5',
            ['type' => 'number', 'enum' => [1.5]],
        ];

        yield 'integer and float literals' => [
            '1|2.5',
            ['type' => ['integer', 'number'], 'enum' => [1, 2.5]],
        ];
    }

    #[DataProvider('provideRejectsTypeStringCases')]
    public function testRejectsTypeString(string $type): void
    {
        $this->expectException(UnsupportedSchemaTypeException::class);

        $this->mapper->map($this->resolver->parseNativeType($type));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsTypeStringCases(): iterable
    {
        yield 'unknown class' => [
            \DateTimeImmutable::class,
        ];

        yield 'intersection' => [
            'Countable&Traversable',
        ];

        yield 'callable' => [
            'callable',
        ];

        yield 'three-argument generic' => [
            'array<int, string, bool>',
        ];

        yield 'class constant' => [
            'Foo::A',
        ];

        yield 'keyless item in a named shape' => [
            'array{a: int, string}',
        ];

        yield 'non-array generic base' => [
            'Generator<int>',
        ];

        yield 'int range single argument' => [
            'int<0>',
        ];

        yield 'int range three arguments' => [
            'int<0, 1, 2>',
        ];

        yield 'int range non-integer bound' => [
            'int<string, 100>',
        ];
    }

    public function testRejectsAllNullUnion(): void
    {
        $this->expectException(UnsupportedSchemaTypeException::class);

        $this->mapper->map(new UnionTypeNode([new IdentifierTypeNode('null'), new IdentifierTypeNode('null')]));
    }

    public function testAConstUnionWithAClassConstantNamesTheUnion(): void
    {
        $this->expectException(UnsupportedSchemaTypeException::class);
        $this->expectExceptionMessageIs('Type "(1 | Foo::A)" is not supported by the input schema generator.');

        $this->mapper->map($this->resolver->parseNativeType('1|Foo::A'));
    }

    #[DataProvider('provideResolvesElementTypeCases')]
    public function testResolvesElementType(string $type, string $expected): void
    {
        $node = $this->resolver->parseNativeType($type);

        self::assertInstanceOf(GenericTypeNode::class, $node);
        self::assertSame($expected, (string) $this->mapper->resolveElementType($node));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideResolvesElementTypeCases(): iterable
    {
        yield 'list' => ['list<int>', 'int'];

        yield 'array with a key type' => ['array<string, bool>', 'bool'];
    }

    public function testElementTypeOfAGenericWithoutArgumentsIsRejected(): void
    {
        $this->expectException(UnsupportedSchemaTypeException::class);

        $this->mapper->resolveElementType(new GenericTypeNode(new IdentifierTypeNode('list'), [], []));
    }

    public function testElementTypeOfAGenericWithThreeArgumentsIsRejected(): void
    {
        $node = $this->resolver->parseNativeType('array<int, string, bool>');

        self::assertInstanceOf(GenericTypeNode::class, $node);
        $this->expectException(UnsupportedSchemaTypeException::class);

        $this->mapper->resolveElementType($node);
    }

    public function testNullableAppendsNullToAnyOf(): void
    {
        self::assertSame(
            ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            $this->mapper->makeNullable(['anyOf' => [['type' => 'string']]]),
        );
    }

    public function testNullableWidensScalarType(): void
    {
        self::assertSame(
            ['type' => ['null', 'string']],
            $this->mapper->makeNullable(['type' => 'string']),
        );
    }

    public function testNullableWidensTypeList(): void
    {
        self::assertSame(
            ['type' => ['null', 'integer', 'string']],
            $this->mapper->makeNullable(['type' => ['integer', 'string']]),
        );
    }

    public function testNullableAppendsNullToEnum(): void
    {
        self::assertSame(
            ['type' => ['null', 'string'], 'enum' => ['a', null]],
            $this->mapper->makeNullable(['type' => 'string', 'enum' => ['a']]),
        );
    }

    public function testNullableLeavesTypelessSchemaUnchanged(): void
    {
        self::assertSame(
            ['minimum' => 1, 'maximum' => 9],
            $this->mapper->makeNullable(['minimum' => 1, 'maximum' => 9]),
        );
    }

    /**
     * @param null|non-empty-string $expected
     */
    #[DataProvider('provideResolveTypeNodeCases')]
    public function testResolveTypeNode(?TypeNode $native, ?TypeNode $doc, ?string $expected): void
    {
        $result = $this->mapper->resolveTypeNode($native, $doc);

        self::assertSame($expected, null === $result ? null : (string) $result);
    }

    /**
     * @return iterable<string, array{null|TypeNode, null|TypeNode, null|non-empty-string}>
     */
    public static function provideResolveTypeNodeCases(): iterable
    {
        $resolver = new DocBlockTypeResolver();

        yield 'no types' => [
            null,
            null,
            null,
        ];

        yield 'native only' => [
            $resolver->parseNativeType('int'),
            null,
            'int',
        ];

        yield 'native alternatives are not refined by different alternatives' => [
            $resolver->parseNativeType('string|false'),
            $resolver->parseNativeType('int|false'),
            '(string | false)',
        ];

        yield 'docblock only' => [
            null,
            $resolver->parseNativeType('int'),
            'int',
        ];

        yield 'native nullable is stripped' => [
            $resolver->parseNativeType('?int'),
            null,
            'int',
        ];

        yield 'union collapses to single member' => [
            $resolver->parseNativeType('int|null'),
            null,
            'int',
        ];

        yield 'union keeps multiple members' => [
            $resolver->parseNativeType('int|string|null'),
            null,
            '(int | string)',
        ];

        yield 'docblock refines bare array' => [
            $resolver->parseNativeType('array'),
            $resolver->parseNativeType('string[]'),
            'string[]',
        ];

        yield 'docblock refines bare array into object map' => [
            $resolver->parseNativeType('array'),
            $resolver->parseNativeType('array<string, int>'),
            'array<string, int>',
        ];

        yield 'bare array without docblock' => [
            $resolver->parseNativeType('array'),
            null,
            'array',
        ];

        yield 'native scalar wins over docblock' => [
            $resolver->parseNativeType('string'),
            $resolver->parseNativeType('string[]'),
            'string',
        ];

        yield 'native array kept when docblock not array-like' => [
            $resolver->parseNativeType('array'),
            $resolver->parseNativeType('string'),
            'array',
        ];

        yield 'unmappable element docblock does not refine bare array' => [
            $resolver->parseNativeType('array'),
            $resolver->parseNativeType(\sprintf('array<string, %s>', \DateTimeImmutable::class)),
            'array',
        ];

        yield 'unmappable generic docblock does not refine bare iterable' => [
            $resolver->parseNativeType('iterable'),
            $resolver->parseNativeType(\sprintf('Generator<int, %s>', \DateTimeImmutable::class)),
            'iterable',
        ];

        yield 'docblock literal union refines native scalar' => [
            $resolver->parseNativeType('string'),
            $resolver->parseNativeType('\'a\'|\'b\''),
            '(\'a\' | \'b\')',
        ];

        yield 'docblock single literal refines native scalar' => [
            $resolver->parseNativeType('string'),
            $resolver->parseNativeType('\'a\''),
            '\'a\'',
        ];

        yield 'docblock scalar union does not refine' => [
            $resolver->parseNativeType('string'),
            $resolver->parseNativeType('int|string'),
            'string',
        ];

        yield 'docblock refines native string base' => [
            $resolver->parseNativeType('string'),
            $resolver->parseNativeType('non-empty-string'),
            'non-empty-string',
        ];

        yield 'docblock refines native int base' => [
            $resolver->parseNativeType('int'),
            $resolver->parseNativeType('positive-int'),
            'positive-int',
        ];

        yield 'docblock int range refines native int' => [
            $resolver->parseNativeType('int'),
            $resolver->parseNativeType('int<0, 100>'),
            'int<0, 100>',
        ];

        yield 'docblock object shape refines native object' => [
            $resolver->parseNativeType('object'),
            $resolver->parseNativeType('object{id: int}'),
            'object{id: int}',
        ];

        yield 'docblock with different base does not refine' => [
            $resolver->parseNativeType('int'),
            $resolver->parseNativeType('non-empty-string'),
            'int',
        ];

        yield 'unmappable docblock does not refine' => [
            $resolver->parseNativeType('string'),
            $resolver->parseNativeType(\DateTimeImmutable::class),
            'string',
        ];
    }

    public function testResolveTypeNodeKeepsAllNullUnion(): void
    {
        $union = new UnionTypeNode([new IdentifierTypeNode('null'), new IdentifierTypeNode('null')]);

        self::assertSame($union, $this->mapper->resolveTypeNode($union, null));
    }

    public function testBuildArraySchemaOmitsItemsWhenThereAreNone(): void
    {
        self::assertSame(['type' => 'array'], TypeNodeSchemaMapper::buildArraySchema([]));
    }

    public function testBuildArraySchemaCarriesTheItemSchema(): void
    {
        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'string']],
            TypeNodeSchemaMapper::buildArraySchema(['type' => 'string']),
        );
    }

    public function testAsSubSchemaSubstitutesTrueForAnUnconstrainedType(): void
    {
        self::assertTrue(TypeNodeSchemaMapper::asSubSchema([]));
    }

    public function testAsSubSchemaLeavesAConstrainedTypeAlone(): void
    {
        self::assertSame(['type' => 'string'], TypeNodeSchemaMapper::asSubSchema(['type' => 'string']));
    }
}
