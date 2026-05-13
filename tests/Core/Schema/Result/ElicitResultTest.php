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
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ElicitResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ElicitResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ElicitResultTest extends TestCase
{
    public function testConstructionMinimal(): void
    {
        $result = new ElicitResult(ElicitAction::Cancel);

        self::assertSame(ElicitAction::Cancel, $result->action);
        self::assertNull($result->content);
        self::assertSame([], $result->meta->toArray());
    }

    public function testToArrayMinimal(): void
    {
        $result = new ElicitResult(ElicitAction::Decline);

        self::assertSame(['action' => 'decline'], $result->toArray());
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new ElicitResult(
            ElicitAction::Accept,
            ['email' => 'a@b.com', 'age' => 30, 'opt' => true, 'topics' => ['php', 'mcp']],
            new MetaObject(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'action' => 'accept',
                'content' => [
                    'email' => 'a@b.com',
                    'age' => 30,
                    'opt' => true,
                    'topics' => ['php', 'mcp'],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ElicitResult(ElicitAction::Accept, ['x' => 'y']);

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $result = ElicitResult::fromArray([
            'action' => 'accept',
            'content' => ['email' => 'a@b.com'],
            '_meta' => ['vendor' => 'x'],
        ]);

        self::assertSame(ElicitAction::Accept, $result->action);
        self::assertSame(['email' => 'a@b.com'], $result->content);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ElicitResult(
            ElicitAction::Accept,
            ['email' => 'a@b.com', 'count' => 5, 'optIn' => true, 'tags' => ['a']],
            new MetaObject(['vendor' => 'x']),
        );

        $rebuilt = ElicitResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsListKeyedContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ElicitResult content must be a string-keyed map.');

        // @phpstan-ignore argument.type
        new ElicitResult(ElicitAction::Accept, ['a']);
    }

    public function testConstructorRejectsEmptyContentKey(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ElicitResult content key must be a non-empty string.');

        new ElicitResult(ElicitAction::Accept, ['' => 'v']);
    }

    public function testConstructorRejectsNonScalarContentValue(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ElicitResult x must be a string, int, bool, or list of strings; got non-list array.');

        // @phpstan-ignore argument.type
        new ElicitResult(ElicitAction::Accept, ['x' => ['k' => 'v']]);
    }

    public function testConstructorRejectsListWithNonStringEntries(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('ElicitResult x list entries must be strings, int given.');

        // @phpstan-ignore argument.type
        new ElicitResult(ElicitAction::Accept, ['x' => [1, 2]]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage($expectedMessage);

        ElicitResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'unknown action' => [
            ['action' => 'maybe'],
            '"maybe" is not a valid backing value for enum Nexus\Mcp\Core\Schema\Enum\ElicitAction',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsAssertInvalidInputCases')]
    public function testFromArrayRejectsAssertInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        ElicitResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsAssertInvalidInputCases(): iterable
    {
        yield 'missing action' => [
            [],
            'ElicitResult data missing "action".',
        ];

        yield 'action not a string' => [
            ['action' => 1],
            'ElicitResult "action" must be a string, int given.',
        ];

        yield 'content not an object' => [
            ['action' => 'accept', 'content' => 'oops'],
            'ElicitResult "content" must be an object, string given.',
        ];

        yield 'content list-keyed' => [
            ['action' => 'accept', 'content' => ['x']],
            'ElicitResult "content" must be a string-keyed object.',
        ];

        yield 'content entry nested object' => [
            ['action' => 'accept', 'content' => ['x' => ['k' => 'v']]],
            'ElicitResult content entry x must be a string, int, bool, or list of strings; got non-list array.',
        ];

        yield 'content entry list with non-string' => [
            ['action' => 'accept', 'content' => ['x' => [1]]],
            'ElicitResult content entry x list entries must be strings, int given.',
        ];
    }
}
