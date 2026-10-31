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

use Amp\NullCancellation;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Server\Discovery\ArgumentBinder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedIntEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedStringEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\Coordinate;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\DeepDto;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\EmptyDto;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\NestedDto;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\OpaqueDto;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\OptionalNestedDto;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\PromotedTagsDto;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\PureEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\SelfReferencingDto;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\Waypoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ArgumentBinder::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ArgumentBinderTest extends AbstractMcpTestCase
{
    private static int $sourceCount = 0;
    private ArgumentBinder $binder;

    #[\Override]
    protected function setUp(): void
    {
        $this->binder = new ArgumentBinder();
    }

    public function testInjectsServerContext(): void
    {
        $context = $this->makeContext();

        self::assertSame([$context], $this->bind('contextOnly', [], $context));
    }

    public function testBindsNamedValue(): void
    {
        self::assertSame(['Ada'], $this->bind('requiredString', ['name' => 'Ada']));
    }

    public function testUsesDefaultWhenValueAbsent(): void
    {
        self::assertSame([3], $this->bind('withDefault', []));
    }

    public function testPrefersProvidedValueOverDefault(): void
    {
        self::assertSame([9], $this->bind('withDefault', ['count' => 9]));
    }

    public function testContinuesBindingPastADefaultedParameter(): void
    {
        self::assertSame([3, 'custom'], $this->bind('twoDefaults', ['label' => 'custom']));
    }

    public function testThrowsWhenRequiredValueMissing(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('missing the required "name" key.');

        $this->bind('requiredString', []);
    }

    public function testHydratesBackedStringEnum(): void
    {
        self::assertSame([BackedStringEnum::A], $this->bind('backedString', ['color' => 'a']));
    }

    public function testHydratesBackedIntEnum(): void
    {
        self::assertSame([BackedIntEnum::One], $this->bind('backedInt', ['level' => 1]));
    }

    public function testHydratesPureEnumByCaseName(): void
    {
        self::assertSame([PureEnum::Yes], $this->bind('pureCase', ['flag' => 'Yes']));
    }

    public function testBoundsAnOverlongPeerArgumentInTheError(): void
    {
        try {
            $this->bind('backedString', ['color' => str_repeat('\'', 200_000)]);
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertSame(256, \strlen($e->getMessage()));
            self::assertStringEndsWith('...', $e->getMessage());
        }
    }

    public function testEscapesControlBytesInAPeerArgumentInTheError(): void
    {
        try {
            $this->bind('pureCase', ['flag' => "ev\x1b[2K\x07il"]);
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertStringContainsString('\'ev\\x1b[2K\\x07il\'', $e->getMessage());
            self::assertDoesNotMatchRegularExpression('/[^\x20-\x7E]/', $e->getMessage());
        }
    }

    public function testPassesThroughNonEnumClassValue(): void
    {
        $payload = new \stdClass();

        self::assertSame([$payload], $this->bind('objectParam', ['payload' => $payload]));
    }

    public function testCastsAMapToAnObjectTypedParameter(): void
    {
        $bound = $this->bind('shapeParam', ['shape' => ['a' => 1, 'b' => 2]]);
        $shape = $bound[0] ?? null;

        self::assertInstanceOf(\stdClass::class, $shape);
        self::assertSame(['a' => 1, 'b' => 2], get_object_vars($shape));
    }

    public function testCastsAMapToAStdClassTypedParameter(): void
    {
        $bound = $this->bind('objectParam', ['payload' => ['k' => 'v']]);
        $payload = $bound[0] ?? null;

        self::assertInstanceOf(\stdClass::class, $payload);
        self::assertSame(['k' => 'v'], get_object_vars($payload));
    }

    public function testCastsAnEmptyMapToAnEmptyObject(): void
    {
        $bound = $this->bind('shapeParam', ['shape' => []]);
        $shape = $bound[0] ?? null;

        self::assertInstanceOf(\stdClass::class, $shape);
        self::assertSame([], get_object_vars($shape));
    }

    public function testRejectsAScalarForAnObjectTypedParameter(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"shape" must be an object, string given.');

        $this->bind('shapeParam', ['shape' => 'scalar']);
    }

    public function testRejectsAListForAnObjectTypedParameter(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"shape" must be an object, array given.');

        $this->bind('shapeParam', ['shape' => [1, 2]]);
    }

    public function testPassesThroughUnionTypedValue(): void
    {
        self::assertSame([5], $this->bind('unionParam', ['value' => 5]));
    }

    public function testPassesThroughAnInterfaceTypedValue(): void
    {
        $when = new \DateTimeImmutable('2026-05-10T12:00:00+00:00');

        self::assertSame([$when], $this->bind('interfaceParam', ['when' => $when]));
    }

    public function testBindsNullToAnUntypedParameter(): void
    {
        self::assertSame([null], $this->bind('untypedParam', ['value' => null]));
    }

    public function testBindsParametersInDeclarationOrder(): void
    {
        $context = $this->makeContext();

        self::assertSame(['Ada', $context, 2], $this->bind('mixedOrder', ['name' => 'Ada', 'age' => 2], $context));
    }

    public function testBindsTrailingDefaultAroundInjectedContext(): void
    {
        $context = $this->makeContext();

        self::assertSame(['Ada', $context, 1], $this->bind('mixedOrder', ['name' => 'Ada'], $context));
    }

    public function testSpreadsAVariadicListIntoThePack(): void
    {
        self::assertSame(['a', 'b', 'c'], $this->bind('variadicStrings', ['tags' => ['a', 'b', 'c']]));
    }

    public function testAbsentVariadicBindsToAnEmptyPack(): void
    {
        self::assertSame([], $this->bind('variadicStrings', []));
    }

    public function testHydratesEachVariadicElement(): void
    {
        self::assertSame(
            [BackedStringEnum::A, BackedStringEnum::B],
            $this->bind('variadicEnums', ['colors' => ['a', 'b']]),
        );
    }

    public function testVariadicElementsFollowLeadingArguments(): void
    {
        self::assertSame(['p', 'x', 'y'], $this->bind('variadicWithLeading', ['prefix' => 'p', 'rest' => ['x', 'y']]));
    }

    public function testRejectsANonListVariadicValue(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"tags" must be a list, string given.');

        $this->bind('variadicStrings', ['tags' => 'solo']);
    }

    public function testConstructsADtoFromAMap(): void
    {
        $bound = $this->bind('withCoordinate', ['point' => ['latitude' => 1.5, 'longitude' => 2.5, 'label' => 'b']]);
        $point = $bound[0] ?? null;

        self::assertInstanceOf(Coordinate::class, $point);
        self::assertSame(1.5, $point->latitude);
        self::assertSame(2.5, $point->longitude);
        self::assertSame(BackedStringEnum::B, $point->label);
    }

    public function testDtoMembersFallBackToConstructorDefaults(): void
    {
        $bound = $this->bind('withCoordinate', ['point' => ['latitude' => 1.0, 'longitude' => 2.0]]);
        $point = $bound[0] ?? null;

        self::assertInstanceOf(Coordinate::class, $point);
        self::assertSame(BackedStringEnum::A, $point->label);
    }

    public function testBindsNullToANullableDtoParameter(): void
    {
        self::assertSame([null], $this->bind('withNullableCoordinate', ['point' => null]));
    }

    public function testBindsNullToANullableEnumParameter(): void
    {
        self::assertSame([null], $this->bind('withNullableEnum', ['colour' => null]));
    }

    public function testBindsNullToANullableEnumFieldInsideADto(): void
    {
        $bound = $this->bind('withWaypoint', ['stop' => ['tag' => null, 'note' => null]]);
        $stop = $bound[0] ?? null;

        self::assertInstanceOf(Waypoint::class, $stop);
        self::assertNull($stop->tag);
        self::assertNull($stop->note);
    }

    public function testANullableDtoParameterStillConstructsFromAMap(): void
    {
        $bound = $this->bind('withNullableCoordinate', ['point' => ['latitude' => 3.5, 'longitude' => 4.5]]);
        $point = $bound[0] ?? null;

        self::assertInstanceOf(Coordinate::class, $point);
        self::assertSame(3.5, $point->latitude);
    }

    public function testNullIsStillRefusedForANonNullableDtoParameter(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"point" must be an object, null given.');

        $this->bind('withCoordinate', ['point' => null]);
    }

    public function testConstructsADtoWithoutAConstructor(): void
    {
        $bound = $this->bind('withEmpty', ['thing' => []]);

        self::assertInstanceOf(EmptyDto::class, $bound[0] ?? null);
    }

    public function testRejectsANonObjectDtoValue(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"point" must be an object, string given.');

        $this->bind('withCoordinate', ['point' => 'scalar']);
    }

    public function testConstructsANestedObject(): void
    {
        $box = $this->bindFirst('withNested', ['box' => ['origin' => ['latitude' => 1.0, 'longitude' => 2.0]]]);

        self::assertInstanceOf(NestedDto::class, $box);
        self::assertSame(1.0, $box->origin->latitude);
        self::assertSame(2.0, $box->origin->longitude);
        self::assertSame(BackedStringEnum::A, $box->origin->label);
    }

    public function testConstructsObjectsTwoLevelsDeep(): void
    {
        $deep = $this->bindFirst(
            'withDeep',
            ['deep' => ['inner' => ['origin' => ['latitude' => 1.0, 'longitude' => 2.0, 'label' => 'b']]]],
        );

        self::assertInstanceOf(DeepDto::class, $deep);
        self::assertSame(BackedStringEnum::B, $deep->inner->origin->label);
    }

    public function testHydratesEnumsTypedByAPromotedPropertyDocblock(): void
    {
        $dto = $this->bindFirst('withPromotedTags', ['dto' => ['tags' => ['x'], 'colors' => ['a', 'b']]]);

        self::assertInstanceOf(PromotedTagsDto::class, $dto);
        self::assertSame([BackedStringEnum::A, BackedStringEnum::B], $dto->colors);
    }

    public function testConstructsAClassThatNestsItselfThroughSelf(): void
    {
        $node = $this->bindFirst('withSelfReferencing', ['node' => ['next' => ['next' => null]]]);

        self::assertInstanceOf(SelfReferencingDto::class, $node);
        self::assertInstanceOf(SelfReferencingDto::class, $node->next);
        self::assertNull($node->next->next);
    }

    public function testBindsNullToANullableNestedObject(): void
    {
        $box = $this->bindFirst('withOptionalNested', ['box' => ['origin' => null]]);

        self::assertInstanceOf(OptionalNestedDto::class, $box);
        self::assertNull($box->origin);
    }

    public function testConstructsANullableNestedObjectFromAValueMap(): void
    {
        $box = $this->bindFirst(
            'withOptionalNested',
            ['box' => ['origin' => ['latitude' => 3.0, 'longitude' => 4.0]]],
        );

        self::assertInstanceOf(OptionalNestedDto::class, $box);
        self::assertSame(3.0, $box->origin?->latitude);
    }

    public function testRejectsANestedObjectThatIsNotAnObject(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"box.origin" must be an object, string given.');

        $this->bind('withNested', ['box' => ['origin' => 'here']]);
    }

    public function testRejectsANestedObjectMissingARequiredMember(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"box.origin" is missing the required "longitude" key.');

        $this->bind('withNested', ['box' => ['origin' => ['latitude' => 1.0]]]);
    }

    public function testRejectsAnUnknownEnumMemberOfANestedObject(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"box.origin.label" must be one of [\'a\', \'b\'], \'zzz\' given.');

        $this->bind(
            'withNested',
            ['box' => ['origin' => ['latitude' => 1.0, 'longitude' => 2.0, 'label' => 'zzz']]],
        );
    }

    public function testRejectsADtoWithAParameterItCannotConstruct(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(\sprintf(
            '%s declares constructor parameter "$at" of type "%s", which the binder cannot construct from a value map.',
            OpaqueDto::class,
            \DateTimeImmutable::class,
        ));

        $this->bind('withOpaque', ['thing' => ['at' => '2026-01-01']]);
    }

    public function testDtoRejectsAMissingRequiredMember(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"point" is missing the required "longitude" key.');

        $this->bind('withCoordinate', ['point' => ['latitude' => 1.0]]);
    }

    public function testHydratesEnumsInsideAGenericList(): void
    {
        self::assertSame(
            [[BackedStringEnum::A, BackedStringEnum::B]],
            $this->bind('listOfEnums', ['colors' => ['a', 'b']]),
        );
    }

    public function testHydratesEnumsInsideAGenericMap(): void
    {
        self::assertSame(
            [['x' => BackedStringEnum::A, 'y' => BackedStringEnum::B]],
            $this->bind('mapOfEnums', ['colours' => ['x' => 'a', 'y' => 'b']]),
        );
    }

    public function testHydratesEnumsInsideAnArrayTypeSuffix(): void
    {
        self::assertSame(
            [[BackedStringEnum::B, BackedStringEnum::A]],
            $this->bindAgainstSource('@param BackedStringEnum[] $colors', 'array $colors', ['colors' => ['b', 'a']]),
        );
    }

    public function testHydratesEnumsInsideNestedLists(): void
    {
        self::assertSame(
            [[[BackedStringEnum::A], [BackedStringEnum::B]]],
            $this->bind('nestedEnums', ['rows' => [['a'], ['b']]]),
        );
    }

    public function testHydratesEnumsInsideAUnionWithNull(): void
    {
        self::assertSame(
            [[BackedStringEnum::A, null]],
            $this->bind('listOfOptionalEnums', ['colors' => ['a', null]]),
        );
    }

    public function testHydratesEnumsInsideANullableElement(): void
    {
        self::assertSame(
            [[null, BackedStringEnum::B]],
            $this->bind('listOfNullableEnums', ['colors' => [null, 'b']]),
        );
    }

    public function testLeavesAnAmbiguousUnionElementAlone(): void
    {
        self::assertSame([['a', 3]], $this->bind('listOfEnumsOrInts', ['mixed' => ['a', 3]]));
    }

    public function testHydratesEnumsInsideAShape(): void
    {
        self::assertSame(
            [['colour' => BackedStringEnum::B, 'size' => 2]],
            $this->bind('shapeWithEnum', ['options' => ['colour' => 'b', 'size' => 2]]),
        );
    }

    public function testSkipsAnAbsentShapeKey(): void
    {
        self::assertSame(
            [['colour' => BackedStringEnum::A]],
            $this->bind('shapeWithEnum', ['options' => ['colour' => 'a']]),
        );
    }

    public function testHydratesEnumsInsideAPositionalShape(): void
    {
        self::assertSame(
            [[BackedIntEnum::Two, 'x']],
            $this->bind('tupleWithEnum', ['pair' => [2, 'x']]),
        );
    }

    public function testHydratesEnumsInsideAKeylessTuple(): void
    {
        self::assertSame(
            [[BackedIntEnum::One, 'x']],
            $this->bind('listTupleWithEnum', ['pair' => [1, 'x']]),
        );
    }

    public function testHydratesEnumsInsideAnUntypedParameter(): void
    {
        self::assertSame(
            [[BackedStringEnum::A]],
            $this->bindAgainstSource('@param list<BackedStringEnum> $colors', '$colors', ['colors' => ['a']]),
        );
    }

    public function testLeavesAnUntypedParameterWithoutADocblockAlone(): void
    {
        self::assertSame([['a']], $this->bindAgainstSource('', '$anything', ['anything' => ['a']]));
    }

    public function testLeavesANonCollectionGenericAlone(): void
    {
        self::assertSame([3], $this->bindAgainstSource('@param int<1, 5> $level', '$level', ['level' => 3]));
    }

    public function testLeavesAGenericOverAnArrayValueAlone(): void
    {
        self::assertSame(
            [['a']],
            $this->bindAgainstSource('@param Generator<BackedStringEnum> $items', '$items', ['items' => ['a']]),
        );
    }

    public function testHydratesDeclaredAndTailEnumsOfAShapeOnce(): void
    {
        self::assertSame(
            [['first' => BackedStringEnum::A, 'second' => BackedStringEnum::B]],
            $this->bind('shapeWithDeclaredAndTailEnums', ['values' => ['first' => 'a', 'second' => 'b']]),
        );
    }

    public function testHydratesEnumsInTheTailOfAnUnsealedTuple(): void
    {
        self::assertSame(
            [['x', BackedStringEnum::A, BackedStringEnum::B]],
            $this->bind('tupleWithEnumTail', ['values' => ['x', 'a', 'b']]),
        );
    }

    public function testHydratesEnumsInTheTailOfAnUnsealedShape(): void
    {
        self::assertSame(
            [['name' => 'n', 'extra' => BackedStringEnum::B]],
            $this->bind('shapeWithEnumTail', ['values' => ['name' => 'n', 'extra' => 'b']]),
        );
    }

    public function testLeavesTheTailOfAnUntypedUnsealedShapeAlone(): void
    {
        self::assertSame(
            [['name' => 'n', 'extra' => 'b']],
            $this->bind('shapeWithOpenTail', ['values' => ['name' => 'n', 'extra' => 'b']]),
        );
    }

    public function testRejectsAnUnknownEnumValueInTheTailOfAShape(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"values.extra" must be one of [\'a\', \'b\'], \'zzz\' given.');

        $this->bind('shapeWithEnumTail', ['values' => ['name' => 'n', 'extra' => 'zzz']]);
    }

    public function testLeavesAnAmbiguousUnionAlone(): void
    {
        self::assertSame(['a'], $this->bind('enumListOrString', ['colors' => 'a']));
        self::assertSame([['a']], $this->bind('enumListOrString', ['colors' => ['a']]));
    }

    public function testLeavesANonArrayValueForACollectionAlone(): void
    {
        self::assertSame(['oops'], $this->bind('listOfEnums', ['colors' => 'oops']));
    }

    public function testLeavesANonArrayValueForAShapeAlone(): void
    {
        self::assertSame(['oops'], $this->bind('shapeWithEnum', ['options' => 'oops']));
    }

    public function testBindsNullToANullableCollectionParameter(): void
    {
        self::assertSame([null], $this->bind('nullableListOfEnums', ['colors' => null]));
    }

    public function testHydratesAnEnumListBehindANullableParameter(): void
    {
        self::assertSame([[BackedStringEnum::B]], $this->bind('nullableListOfEnums', ['colors' => ['b']]));
    }

    public function testRejectsAnUnknownEnumValueInsideACollection(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"colors.1" must be one of [\'a\', \'b\'], \'zzz\' given.');

        $this->bind('listOfEnums', ['colors' => ['a', 'zzz']]);
    }

    public function testRejectsAnUnknownEnumValueInsideAShape(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"options.colour" must be one of [\'a\', \'b\'], \'zzz\' given.');

        $this->bind('shapeWithEnum', ['options' => ['colour' => 'zzz']]);
    }

    public function testRejectsUnknownBackedEnumValue(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"color" must be one of [\'a\', \'b\'], \'zzz\' given.');

        $this->bind('backedString', ['color' => 'zzz']);
    }

    public function testCatchesTypeErrorWhenIntBackedEnumGetsString(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"level" must be one of [1, 2], \'2\' given.');

        $this->bind('backedInt', ['level' => '2']);
    }

    public function testRejectsUnknownPureEnumCaseName(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"flag" must be one of [\'Yes\', \'No\'], \'Nope\' given.');

        $this->bind('pureCase', ['flag' => 'Nope']);
    }

    public function testRejectsPureEnumValueOfNonStringType(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"flag" must be one of [\'Yes\', \'No\'], 5 given.');

        $this->bind('pureCase', ['flag' => 5]);
    }

    public function testABindingFailureCarriesTheRequestIdAndTheOriginalCause(): void
    {
        try {
            $this->bind('requiredString', []);
        } catch (InvalidParamsException $e) {
            self::assertSame(7, $e->requestId?->id);
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());

            return;
        }

        self::fail('Expected an InvalidParamsException.');
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<mixed>
     */
    private function bind(string $method, array $values, ?ServerContext $context = null): array
    {
        return $this->binder->bind(
            new \ReflectionMethod(ReflectedHandlers::class, $method),
            $values,
            $context ?? $this->makeContext(),
        );
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<mixed>
     */
    private function bindAgainstSource(string $docblock, string $parameter, array $values): array
    {
        $class = 'BinderSource'.++self::$sourceCount;
        $file = tempnam(sys_get_temp_dir(), 'binder');
        self::assertIsString($file);

        file_put_contents($file, \sprintf(
            <<<'PHP'
                <?php
                use %s;
                final class %s {
                    /**
                     * %s
                     */
                    public function handle(%s) {}
                }

                PHP,
            BackedStringEnum::class,
            $class,
            $docblock,
            $parameter,
        ));

        try {
            require_once $file;

            return $this->binder->bind(new \ReflectionMethod($class, 'handle'), $values, $this->makeContext());
        } finally {
            unlink($file);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function bindFirst(string $method, array $values): mixed
    {
        return $this->bind($method, $values)[0] ?? null;
    }

    private function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
