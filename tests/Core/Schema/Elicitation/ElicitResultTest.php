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
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ElicitResult::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ElicitResultTest extends AbstractMcpTestCase
{
    public function testConstructionMinimal(): void
    {
        $result = new ElicitResult(action: ElicitAction::Cancel);

        self::assertSame(ElicitAction::Cancel, $result->action);
        self::assertNull($result->content);
    }

    public function testToArrayMinimal(): void
    {
        $result = new ElicitResult(action: ElicitAction::Decline);

        self::assertSame(['action' => 'decline'], $result->toArray());
    }

    public function testToArrayWithContent(): void
    {
        $result = new ElicitResult(
            action: ElicitAction::Accept,
            content: ['email' => 'a@b.com', 'age' => 30, 'rating' => 3.5, 'opt' => true, 'topics' => ['php', 'mcp']],
        );

        self::assertSame(
            [
                'action' => 'accept',
                'content' => [
                    'email' => 'a@b.com',
                    'age' => 30,
                    'rating' => 3.5,
                    'opt' => true,
                    'topics' => ['php', 'mcp'],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new ElicitResult(action: ElicitAction::Accept, content: ['x' => 'y']);

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayParsesAllFields(): void
    {
        $result = ElicitResult::fromArray([
            'action' => 'accept',
            'content' => ['email' => 'a@b.com'],
        ]);

        self::assertSame(ElicitAction::Accept, $result->action);
        self::assertSame(['email' => 'a@b.com'], $result->content);
    }

    public function testFromArrayAcceptsFloatContentValue(): void
    {
        $result = ElicitResult::fromArray([
            'action' => 'accept',
            'content' => ['rating' => 3.5],
        ]);

        self::assertSame(ElicitAction::Accept, $result->action);
        self::assertSame(['rating' => 3.5], $result->content);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new ElicitResult(
            action: ElicitAction::Accept,
            content: ['email' => 'a@b.com', 'count' => 5, 'optIn' => true, 'tags' => ['a']],
        );

        $rebuilt = ElicitResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorAcceptsAContentKeyThatIsAllDigits(): void
    {
        $result = new ElicitResult(action: ElicitAction::Accept, content: ['0' => 'a']);

        self::assertSame([0 => 'a'], $result->content);
        self::assertSame('{"action":"accept","content":{"0":"a"}}', json_encode($result));
    }

    public function testConstructorRejectsEmptyContentKey(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each elicit result "content" key must be an int or non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new ElicitResult(action: ElicitAction::Accept, content: ['' => 'v']);
    }

    public function testConstructorRejectsNonScalarContentValue(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('elicit result "x" must be a string, int, float, bool, or list of strings, non-list array given.');

        // @phpstan-ignore argument.type
        new ElicitResult(action: ElicitAction::Accept, content: ['x' => ['k' => 'v']]);
    }

    public function testAnEmptyStringInsideAListValueIsAccepted(): void
    {
        $result = new ElicitResult(action: ElicitAction::Accept, content: ['tags' => ['a', '']]);

        self::assertSame(['tags' => ['a', '']], $result->content);

        $rebuilt = ElicitResult::fromArray($result->toArray());

        self::assertSame($result->toArray(), $rebuilt->toArray());
    }

    public function testAnEmptyStringScalarValueIsAccepted(): void
    {
        $result = new ElicitResult(action: ElicitAction::Accept, content: ['note' => '']);

        self::assertSame(['note' => ''], $result->content);
    }

    public function testConstructorRejectsListWithNonStringEntries(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('each elicit result "x" list entry must be a string, int given.');

        // @phpstan-ignore argument.type
        new ElicitResult(action: ElicitAction::Accept, content: ['x' => [1, 2]]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        ElicitResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing action' => [
            [],
            'elicit result is missing the required "action" key.',
        ];

        yield 'action not a string' => [
            ['action' => 1],
            'elicit result "action" must be one of [\'accept\', \'decline\', \'cancel\'], 1 given.',
        ];

        yield 'unknown action' => [
            ['action' => 'maybe'],
            'elicit result "action" must be one of [\'accept\', \'decline\', \'cancel\'], \'maybe\' given.',
        ];

        yield 'content not an object' => [
            ['action' => 'accept', 'content' => 'oops'],
            'elicit result "content" must be an object, string given.',
        ];

        yield 'content entry nested object' => [
            ['action' => 'accept', 'content' => ['x' => ['k' => 'v']]],
            'elicit result "content entry x" must be a string, int, float, bool, or list of strings, non-list array given.',
        ];

        yield 'content entry list with non-string' => [
            ['action' => 'accept', 'content' => ['x' => [1]]],
            'each elicit result "content entry x" list entry must be a string, int given.',
        ];
    }
}
