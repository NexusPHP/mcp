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
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Server\Discovery\ArgumentBinder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedIntEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\BackedStringEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\PureEnum;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ArgumentBinder::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ArgumentBinderTest extends TestCase
{
    private ArgumentBinder $binder;

    #[\Override]
    protected function setUp(): void
    {
        $this->binder = new ArgumentBinder();
    }

    public function testInjectsServerContext(): void
    {
        $context = self::makeContext();

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
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The "name" argument is required.');

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

    public function testPassesThroughNonEnumClassValue(): void
    {
        $payload = new \stdClass();

        self::assertSame([$payload], $this->bind('objectParam', ['payload' => $payload]));
    }

    public function testPassesThroughUnionTypedValue(): void
    {
        self::assertSame([5], $this->bind('unionParam', ['value' => 5]));
    }

    public function testBindsParametersInDeclarationOrder(): void
    {
        $context = self::makeContext();

        self::assertSame(['Ada', $context, 2], $this->bind('mixedOrder', ['name' => 'Ada', 'age' => 2], $context));
    }

    public function testBindsTrailingDefaultAroundInjectedContext(): void
    {
        $context = self::makeContext();

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
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('The "tags" argument must be a list, string given.');

        $this->bind('variadicStrings', ['tags' => 'solo']);
    }

    public function testRejectsUnknownBackedEnumValue(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Parameter "$color" must be one of [\'a\', \'b\'], \'zzz\' given.');

        $this->bind('backedString', ['color' => 'zzz']);
    }

    public function testCatchesTypeErrorWhenIntBackedEnumGetsString(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Parameter "$level" must be one of [1, 2], \'2\' given.');

        $this->bind('backedInt', ['level' => '2']);
    }

    public function testRejectsUnknownPureEnumCaseName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Parameter "$flag" must be one of [\'Yes\', \'No\'], \'Nope\' given.');

        $this->bind('pureCase', ['flag' => 'Nope']);
    }

    public function testRejectsPureEnumValueOfNonStringType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Parameter "$flag" must be one of [\'Yes\', \'No\'], 5 given.');

        $this->bind('pureCase', ['flag' => 5]);
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
            $context ?? self::makeContext(),
        );
    }

    private static function makeContext(?string $sessionId = 'session-1'): ServerContext
    {
        return new ServerContext(
            new RequestId(7),
            new NullCancellation(),
            new RequestMetaObject(),
            $sessionId,
            new RecordingSender(),
        );
    }
}
