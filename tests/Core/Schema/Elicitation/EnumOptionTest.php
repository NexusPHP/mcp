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
use Nexus\Mcp\Core\Schema\Elicitation\EnumOption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EnumOption::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class EnumOptionTest extends TestCase
{
    public function testConstruction(): void
    {
        $option = new EnumOption(const: 'free', title: 'Free plan');

        self::assertSame('free', $option->const);
        self::assertSame('Free plan', $option->title);
    }

    public function testToArray(): void
    {
        $option = new EnumOption(const: 'pro', title: 'Pro plan');

        self::assertSame(['const' => 'pro', 'title' => 'Pro plan'], $option->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $option = new EnumOption(const: 'free', title: 'Free plan');

        self::assertSame($option->toArray(), $option->jsonSerialize());
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new EnumOption(const: 'pro', title: 'Pro plan');

        $rebuilt = EnumOption::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsEmptyConst(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"oneOf.const" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new EnumOption(const: '', title: 'Title');
    }

    public function testConstructorRejectsEmptyTitle(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"oneOf.title" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new EnumOption(const: 'value', title: '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        EnumOption::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing const' => [
            ['title' => 'x'],
            '"oneOf" is missing the required "const" key.',
        ];

        yield 'const not a string' => [
            ['const' => 1, 'title' => 'x'],
            '"oneOf.const" must be a non-empty string, int given.',
        ];

        yield 'missing title' => [
            ['const' => 'x'],
            '"oneOf" is missing the required "title" key.',
        ];

        yield 'title not a string' => [
            ['const' => 'x', 'title' => 1],
            '"oneOf.title" must be a non-empty string, int given.',
        ];
    }
}
