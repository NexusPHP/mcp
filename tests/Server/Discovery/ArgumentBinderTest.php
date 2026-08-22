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
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\EmptyDto;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\NestedDto;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\PureEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
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

    public function testRejectsADtoWithANestedObjectParameter(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(\sprintf(
            '%s declares constructor parameter "$origin" of type "%s", which the binder cannot construct from a value map. Nested object expansion is not supported.',
            NestedDto::class,
            Coordinate::class,
        ));

        $this->bind('withNested', ['box' => ['origin' => ['latitude' => 1.0, 'longitude' => 2.0]]]);
    }

    public function testDtoRejectsAMissingRequiredMember(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"point" is missing the required "longitude" key.');

        $this->bind('withCoordinate', ['point' => ['latitude' => 1.0]]);
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
