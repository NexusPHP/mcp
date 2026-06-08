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

        self::assertSame(['resultType' => 'complete', 'action' => 'decline'], $result->toArray());
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
                'resultType' => 'complete',
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
        $this->expectExceptionMessageIs('"result.content" must be a string-keyed map.');

        // @phpstan-ignore argument.type
        new ElicitResult(ElicitAction::Accept, ['a']);
    }

    public function testConstructorRejectsEmptyContentKey(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "result.content" key must be a non-empty string.');

        new ElicitResult(ElicitAction::Accept, ['' => 'v']);
    }

    public function testConstructorRejectsNonScalarContentValue(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result" "x" must be a string, int, bool, or list of strings, non-list array given.');

        // @phpstan-ignore argument.type
        new ElicitResult(ElicitAction::Accept, ['x' => ['k' => 'v']]);
    }

    public function testConstructorRejectsListWithNonStringEntries(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each "result" "x" list entry must be a string, int given.');

        // @phpstan-ignore argument.type
        new ElicitResult(ElicitAction::Accept, ['x' => [1, 2]]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsAssertInvalidInputCases')]
    public function testFromArrayRejectsAssertInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ElicitResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsAssertInvalidInputCases(): iterable
    {
        yield 'missing action' => [
            [],
            '"result" missing the required "action" key.',
        ];

        yield 'action not a string' => [
            ['action' => 1],
            '"result.action" must be one of [\'accept\', \'decline\', \'cancel\'], 1 given.',
        ];

        yield 'unknown action' => [
            ['action' => 'maybe'],
            '"result.action" must be one of [\'accept\', \'decline\', \'cancel\'], \'maybe\' given.',
        ];

        yield 'content not an object' => [
            ['action' => 'accept', 'content' => 'oops'],
            '"result.content" must be an object, string given.',
        ];

        yield 'content list-keyed' => [
            ['action' => 'accept', 'content' => ['x']],
            '"result.content" must be a string-keyed object.',
        ];

        yield 'content entry nested object' => [
            ['action' => 'accept', 'content' => ['x' => ['k' => 'v']]],
            '"result" "content entry x" must be a string, int, bool, or list of strings, non-list array given.',
        ];

        yield 'content entry list with non-string' => [
            ['action' => 'accept', 'content' => ['x' => [1]]],
            'each "result" "content entry x" list entry must be a string, int given.',
        ];
    }
}
