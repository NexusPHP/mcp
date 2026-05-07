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
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\PromptMessage;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GetPromptResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class GetPromptResultTest extends TestCase
{
    public function testConstructionDefaults(): void
    {
        $result = new GetPromptResult([new PromptMessage(Role::User, new TextContent('hi'))]);

        self::assertCount(1, $result->messages);
        self::assertNull($result->description);
        self::assertNull($result->meta);
    }

    public function testConstructionAcceptsEmptyMessagesList(): void
    {
        $result = new GetPromptResult([]);

        self::assertSame([], $result->messages);
    }

    public function testToArrayEmitsMessages(): void
    {
        $result = new GetPromptResult([new PromptMessage(Role::User, new TextContent('hi'))]);

        self::assertSame(
            [
                'messages' => [
                    [
                        'content' => ['text' => 'hi', 'type' => 'text'],
                        'role' => 'user',
                    ],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayWithAllFields(): void
    {
        $result = new GetPromptResult(
            [new PromptMessage(Role::User, new TextContent('hi'))],
            'Reviews changes.',
            new Meta(['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'messages' => [
                    [
                        'content' => ['text' => 'hi', 'type' => 'text'],
                        'role' => 'user',
                    ],
                ],
                'description' => 'Reviews changes.',
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = new GetPromptResult(
            [new PromptMessage(Role::User, new TextContent('hi'))],
            'desc',
            new Meta(['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new GetPromptResult(
            [
                new PromptMessage(Role::User, new TextContent('hi')),
                new PromptMessage(Role::Assistant, new TextContent('there')),
            ],
            'desc',
            new Meta(['vendor' => 'x']),
        );

        $rebuilt = GetPromptResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListMessages(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('GetPromptResult messages must be a list, got non-list array.');

        // @phpstan-ignore argument.type
        new GetPromptResult([5 => new PromptMessage(Role::User, new TextContent('x'))]);
    }

    public function testConstructorRejectsNonPromptMessageElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new GetPromptResult([42]);
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('GetPromptResult description must be a non-empty string or null.');

        new GetPromptResult([], '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidWireDataCases')]
    public function testFromArrayRejectsInvalidWireData(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        GetPromptResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidWireDataCases(): iterable
    {
        yield 'missing messages' => [
            [],
            'GetPromptResult wire data missing "messages".',
        ];

        yield 'messages not an array' => [
            ['messages' => 'oops'],
            'GetPromptResult wire "messages" must be an array, string given.',
        ];

        yield 'message entry not an object' => [
            ['messages' => ['oops']],
            'GetPromptResult wire message entry must be an object, string given.',
        ];

        yield 'message entry list-keyed' => [
            ['messages' => [['x']]],
            'GetPromptResult wire message entry must be a string-keyed object.',
        ];

        yield 'description not a string' => [
            ['messages' => [], 'description' => 1],
            'GetPromptResult wire "description" must be a string or null, int given.',
        ];

        yield '_meta not an object' => [
            ['messages' => [], '_meta' => 'oops'],
            'Result "_meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['messages' => [], '_meta' => ['x']],
            'Result "_meta" must be a string-keyed object.',
        ];
    }
}
