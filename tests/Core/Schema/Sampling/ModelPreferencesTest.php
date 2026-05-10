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

namespace Nexus\Mcp\Tests\Core\Schema\Sampling;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Sampling\ModelHint;
use Nexus\Mcp\Core\Schema\Sampling\ModelPreferences;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ModelPreferences::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ModelPreferencesTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $prefs = new ModelPreferences();

        self::assertNull($prefs->hints);
        self::assertNull($prefs->costPriority);
        self::assertNull($prefs->speedPriority);
        self::assertNull($prefs->intelligencePriority);
    }

    public function testConstructionFull(): void
    {
        $prefs = new ModelPreferences([new ModelHint('sonnet')], 0.2, 0.5, 0.9);

        self::assertCount(1, $prefs->hints ?? []);
        self::assertSame(0.2, $prefs->costPriority);
        self::assertSame(0.5, $prefs->speedPriority);
        self::assertSame(0.9, $prefs->intelligencePriority);
    }

    public function testConstructorRejectsNonHintEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Value "\'not-a-hint\'" is expected to be an instance of \'Nexus\\\\Mcp\\\\Core\\\\Schema\\\\Sampling\\\\ModelHint\' but got string instead.');

        new ModelPreferences(['not-a-hint']); // @phpstan-ignore argument.type
    }

    #[DataProvider('provideConstructorRejectsOutOfRangeFieldCases')]
    public function testConstructorRejectsOutOfRangeField(string $field, float $value, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new ModelPreferences(
            costPriority: 'costPriority' === $field ? $value : null,
            speedPriority: 'speedPriority' === $field ? $value : null,
            intelligencePriority: 'intelligencePriority' === $field ? $value : null,
        );
    }

    /**
     * @return iterable<string, array{string, float, string}>
     */
    public static function provideConstructorRejectsOutOfRangeFieldCases(): iterable
    {
        yield 'costPriority below 0' => ['costPriority', -0.1, 'ModelPreferences "costPriority" must be between 0.0 and 1.0.'];

        yield 'costPriority above 1' => ['costPriority', 1.1, 'ModelPreferences "costPriority" must be between 0.0 and 1.0.'];

        yield 'speedPriority below 0' => ['speedPriority', -0.1, 'ModelPreferences "speedPriority" must be between 0.0 and 1.0.'];

        yield 'speedPriority above 1' => ['speedPriority', 1.1, 'ModelPreferences "speedPriority" must be between 0.0 and 1.0.'];

        yield 'intelligencePriority below 0' => ['intelligencePriority', -0.1, 'ModelPreferences "intelligencePriority" must be between 0.0 and 1.0.'];

        yield 'intelligencePriority above 1' => ['intelligencePriority', 1.1, 'ModelPreferences "intelligencePriority" must be between 0.0 and 1.0.'];
    }

    public function testConstructorAcceptsBoundaryValues(): void
    {
        $prefs = new ModelPreferences(costPriority: 0.0, speedPriority: 1.0, intelligencePriority: 0.5);

        self::assertSame(0.0, $prefs->costPriority);
        self::assertSame(1.0, $prefs->speedPriority);
        self::assertSame(0.5, $prefs->intelligencePriority);
    }

    public function testToArrayOmitsAbsentFields(): void
    {
        self::assertSame([], new ModelPreferences()->toArray());
    }

    public function testToArrayEmitsHintsAsArrays(): void
    {
        $prefs = new ModelPreferences([new ModelHint('sonnet')]);

        self::assertSame(['hints' => [['name' => 'sonnet']]], $prefs->toArray());
    }

    public function testToArrayPreservesAllFields(): void
    {
        $prefs = new ModelPreferences([new ModelHint('sonnet')], 0.2, 0.5, 0.9);

        self::assertSame(
            [
                'hints' => [['name' => 'sonnet']],
                'costPriority' => 0.2,
                'speedPriority' => 0.5,
                'intelligencePriority' => 0.9,
            ],
            $prefs->toArray(),
        );
    }

    public function testJsonSerializeConvertsHintsToArrays(): void
    {
        $prefs = new ModelPreferences([new ModelHint('sonnet'), new ModelHint('haiku')]);

        self::assertSame(
            ['hints' => [['name' => 'sonnet'], ['name' => 'haiku']]],
            $prefs->jsonSerialize(),
        );
    }

    public function testJsonSerializeStdClassWhenEmpty(): void
    {
        self::assertInstanceOf(\stdClass::class, new ModelPreferences()->jsonSerialize());
        self::assertSame('{}', json_encode(new ModelPreferences()));
    }

    public function testJsonSerializeWrapsEmptyHints(): void
    {
        $prefs = new ModelPreferences([new ModelHint()]);

        $encoded = json_encode($prefs);

        self::assertSame('{"hints":[{}]}', $encoded);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ModelPreferences([new ModelHint('sonnet')], 0.2, 0.5, 0.9);

        $rebuilt = ModelPreferences::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayCoercesIntPriorityToFloat(): void
    {
        $prefs = ModelPreferences::fromArray(['costPriority' => 1]);

        self::assertSame(1.0, $prefs->costPriority);
    }

    public function testFromArrayRejectsNonArrayHints(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ModelPreferences wire "hints" must be an array, string given.');

        ModelPreferences::fromArray(['hints' => 'oops']);
    }

    public function testFromArrayRejectsNonObjectHintEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ModelPreferences wire hint entry must be an object, string given.');

        ModelPreferences::fromArray(['hints' => ['oops']]);
    }

    public function testFromArrayRejectsListHintEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ModelPreferences wire hint entry must be a string-keyed object.');

        ModelPreferences::fromArray(['hints' => [['x']]]);
    }

    public function testFromArrayRejectsNonNumericPriority(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ModelPreferences wire "speedPriority" must be a number or null, string given.');

        ModelPreferences::fromArray(['speedPriority' => 'oops']);
    }
}
