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
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
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
        $result = new GetPromptResult(messages: [new PromptMessage(role: Role::User, content: new TextContent(text: 'hi'))]);

        self::assertCount(1, $result->messages);
        self::assertNull($result->description);
        self::assertSame([], $result->meta->toArray());
    }

    public function testConstructionAcceptsEmptyMessagesList(): void
    {
        $result = new GetPromptResult(messages: []);

        self::assertSame([], $result->messages);
    }

    public function testToArrayEmitsMessages(): void
    {
        $result = new GetPromptResult(messages: [new PromptMessage(role: Role::User, content: new TextContent(text: 'hi'))]);

        self::assertSame(
            [
                'resultType' => 'complete',
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
            messages: [new PromptMessage(role: Role::User, content: new TextContent(text: 'hi'))],
            description: 'Reviews changes.',
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'x'],
                'resultType' => 'complete',
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
            messages: [new PromptMessage(role: Role::User, content: new TextContent(text: 'hi'))],
            description: 'desc',
            meta: new MetaObject(extras: ['k' => 'v']),
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new GetPromptResult(
            messages: [
                new PromptMessage(role: Role::User, content: new TextContent(text: 'hi')),
                new PromptMessage(role: Role::Assistant, content: new TextContent(text: 'there')),
            ],
            description: 'desc',
            meta: new MetaObject(extras: ['vendor' => 'x']),
        );

        $rebuilt = GetPromptResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testConstructorRejectsNonListMessages(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.messages" must be a list, non-list array given.');

        // @phpstan-ignore argument.type
        new GetPromptResult(messages: [5 => new PromptMessage(role: Role::User, content: new TextContent(text: 'x'))]);
    }

    public function testConstructorRejectsNonPromptMessageElement(): void
    {
        $this->expectException(ExpectationFailedException::class);

        // @phpstan-ignore argument.type
        new GetPromptResult(messages: [42]);
    }

    public function testConstructorRejectsEmptyDescription(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"result.description" must be a non-empty string or null.');

        new GetPromptResult(messages: [], description: '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        GetPromptResult::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing messages' => [
            [],
            '"result" missing the required "messages" key.',
        ];

        yield 'messages not an array' => [
            ['messages' => 'oops'],
            '"result.messages" must be an array, string given.',
        ];

        yield 'message entry not an object' => [
            ['messages' => ['oops']],
            '"result.message" must be an object, string given.',
        ];

        yield 'message entry list-keyed' => [
            ['messages' => [['x']]],
            '"result.message" must be a string-keyed object.',
        ];

        yield 'description not a string' => [
            ['messages' => [], 'description' => 1],
            '"result.description" must be a string or null, int given.',
        ];

        yield '_meta not an object' => [
            ['messages' => [], '_meta' => 'oops'],
            '"result._meta" must be an object, string given.',
        ];

        yield '_meta list-keyed' => [
            ['messages' => [], '_meta' => ['x']],
            '"result._meta" must be a string-keyed object.',
        ];
    }
}
