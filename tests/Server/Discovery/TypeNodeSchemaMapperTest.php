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
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedIntEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedStringEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\PureEnum;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TypeNodeSchemaMapper::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class TypeNodeSchemaMapperTest extends TestCase
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

        yield 'mixed scalar and array union' => [
            'int|string[]',
        ];

        yield 'mixed literal types' => [
            '\'a\'|1',
        ];

        yield 'literal mixed with general type' => [
            '\'a\'|int',
        ];

        yield 'float literal' => [
            '1.5',
        ];

        yield 'bool literal union' => [
            'true|false',
        ];

        yield 'non-identifier shape key' => [
            'array{0: int}',
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

    public function testNullableWidensScalarType(): void
    {
        self::assertSame(
            ['type' => ['null', 'string']],
            $this->mapper->nullable(['type' => 'string']),
        );
    }

    public function testNullableWidensTypeList(): void
    {
        self::assertSame(
            ['type' => ['null', 'integer', 'string']],
            $this->mapper->nullable(['type' => ['integer', 'string']]),
        );
    }

    public function testNullableAppendsNullToEnum(): void
    {
        self::assertSame(
            ['type' => ['null', 'string'], 'enum' => ['a', null]],
            $this->mapper->nullable(['type' => 'string', 'enum' => ['a']]),
        );
    }

    public function testNullableLeavesTypelessSchemaUnchanged(): void
    {
        self::assertSame(
            ['minimum' => 1, 'maximum' => 9],
            $this->mapper->nullable(['minimum' => 1, 'maximum' => 9]),
        );
    }

    /**
     * @param null|non-empty-string $expected
     */
    #[DataProvider('provideChooseCoreCases')]
    public function testChooseCore(?TypeNode $native, ?TypeNode $doc, ?string $expected): void
    {
        $result = $this->mapper->chooseCore($native, $doc);

        self::assertSame($expected, null === $result ? null : (string) $result);
    }

    /**
     * @return iterable<string, array{null|TypeNode, null|TypeNode, null|non-empty-string}>
     */
    public static function provideChooseCoreCases(): iterable
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

    public function testChooseCoreKeepsAllNullUnion(): void
    {
        $union = new UnionTypeNode([new IdentifierTypeNode('null'), new IdentifierTypeNode('null')]);

        self::assertSame($union, $this->mapper->chooseCore($union, null));
    }
}
