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

namespace Nexus\Mcp\Tests\Core\Schema\Result;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CompleteResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CompleteResultTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $result = new CompleteResult(['values' => ['auth']]);

        self::assertSame(['values' => ['auth']], $result->completion);
        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructionAcceptsEmptyValues(): void
    {
        $result = new CompleteResult(['values' => []]);

        self::assertSame(['values' => []], $result->completion);
    }

    public function testConstructionWithAllFields(): void
    {
        $result = new CompleteResult(
            ['values' => ['auth', 'auth-bearer'], 'total' => 2, 'hasMore' => false],
            new MetaObject(['vendor.brand' => 'acme']),
        );

        self::assertSame(
            ['values' => ['auth', 'auth-bearer'], 'total' => 2, 'hasMore' => false],
            $result->completion,
        );
    }

    public function testToArrayMinimal(): void
    {
        $result = new CompleteResult(['values' => ['auth']]);

        self::assertSame(
            ['resultType' => 'complete', 'completion' => ['values' => ['auth']]],
            $result->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new CompleteResult(
            ['values' => ['auth'], 'total' => 1, 'hasMore' => false],
            new MetaObject(['vendor.brand' => 'acme']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor.brand' => 'acme'],
                'resultType' => 'complete',
                'completion' => ['values' => ['auth'], 'total' => 1, 'hasMore' => false],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new CompleteResult(['values' => ['auth']]);

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new CompleteResult(
            ['values' => ['auth', 'auth-bearer'], 'total' => 2, 'hasMore' => true],
            new MetaObject(['vendor.brand' => 'acme']),
        );

        $rebuilt = CompleteResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListValues(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.completion.values" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new CompleteResult(['values' => [5 => 'auth']]);
    }

    public function testConstructorRejectsNonStringValueElement(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "result.completion.values" must be a string, int given.');

        // @phpstan-ignore argument.type
        new CompleteResult(['values' => [1]]);
    }

    public function testConstructorRejectsNonIntTotal(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.completion.total" must be an int, string given.');

        // @phpstan-ignore argument.type
        new CompleteResult(['values' => [], 'total' => 'oops']);
    }

    public function testConstructorRejectsNonBoolHasMore(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.completion.hasMore" must be a bool, string given.');

        // @phpstan-ignore argument.type
        new CompleteResult(['values' => [], 'hasMore' => 'oops']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        CompleteResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing completion' => [
            [],
            '"result" missing the required "completion" key.',
        ];

        yield 'completion not an object' => [
            ['completion' => 'oops'],
            '"result.completion" must be an object, string given.',
        ];

        yield 'completion list-keyed' => [
            ['completion' => ['x']],
            '"result.completion" must be a string-keyed object.',
        ];

        yield 'completion missing values' => [
            ['completion' => []],
            '"result" missing the required "completion.values" key.',
        ];

        yield 'completion values not an array' => [
            ['completion' => ['values' => 'oops']],
            '"result.completion.values" must be a list, string given.',
        ];

        yield 'completion values not a list' => [
            ['completion' => ['values' => [5 => 'auth']]],
            '"result.completion.values" must be a list, array given.',
        ];

        yield 'completion value not a string' => [
            ['completion' => ['values' => [1]]],
            'each "result.completion.values" must be a string, int given.',
        ];

        yield 'completion total not an int' => [
            ['completion' => ['values' => [], 'total' => 'oops']],
            '"result.completion.total" must be an int, string given.',
        ];

        yield 'completion hasMore not a bool' => [
            ['completion' => ['values' => [], 'hasMore' => 'oops']],
            '"result.completion.hasMore" must be a bool, string given.',
        ];

        yield '_meta not an object' => [
            ['completion' => ['values' => []], '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['completion' => ['values' => []], '_meta' => ['x']],
            '"result._meta" must be a string-keyed object.',
        ];
    }
}
