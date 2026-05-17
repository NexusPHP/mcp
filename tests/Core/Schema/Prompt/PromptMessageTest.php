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

namespace Nexus\Mcp\Tests\Core\Schema\Prompt;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PromptMessage::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PromptMessageTest extends TestCase
{
    public function testConstructionWithText(): void
    {
        $message = new PromptMessage(Role::User, new TextContent('hello'));

        self::assertSame(Role::User, $message->role);

        if (! $message->content instanceof TextContent) {
            self::fail('Expected content to be TextContent.');
        }

        self::assertSame('hello', $message->content->text);
    }

    public function testToArrayWithText(): void
    {
        $message = new PromptMessage(Role::User, new TextContent('hello'));

        self::assertSame(
            [
                'content' => ['text' => 'hello', 'type' => 'text'],
                'role' => 'user',
            ],
            $message->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $message = new PromptMessage(Role::Assistant, new TextContent('hi'));

        self::assertSame($message->toArray(), $message->jsonSerialize());
    }

    public function testFromArrayDispatchesText(): void
    {
        $message = PromptMessage::fromArray([
            'role' => 'user',
            'content' => ['type' => 'text', 'text' => 'hello'],
        ]);

        if (! $message->content instanceof TextContent) {
            self::fail('Expected content to be TextContent.');
        }

        self::assertSame('hello', $message->content->text);
    }

    public function testFromArrayDispatchesImage(): void
    {
        $message = PromptMessage::fromArray([
            'role' => 'user',
            'content' => ['type' => 'image', 'data' => 'aGVsbG8=', 'mimeType' => 'image/png'],
        ]);

        self::assertInstanceOf(ImageContent::class, $message->content);
    }

    public function testFromArrayDispatchesAudio(): void
    {
        $message = PromptMessage::fromArray([
            'role' => 'user',
            'content' => ['type' => 'audio', 'data' => 'aGVsbG8=', 'mimeType' => 'audio/mp3'],
        ]);

        self::assertInstanceOf(AudioContent::class, $message->content);
    }

    public function testFromArrayDispatchesResourceLink(): void
    {
        $message = PromptMessage::fromArray([
            'role' => 'user',
            'content' => ['type' => 'resource_link', 'name' => 'doc', 'uri' => 'file:///x'],
        ]);

        self::assertInstanceOf(ResourceLink::class, $message->content);
    }

    public function testFromArrayDispatchesEmbeddedResource(): void
    {
        $message = PromptMessage::fromArray([
            'role' => 'user',
            'content' => [
                'type' => 'resource',
                'resource' => ['uri' => 'file:///x', 'text' => 'hello'],
            ],
        ]);

        self::assertInstanceOf(EmbeddedResource::class, $message->content);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new PromptMessage(
            Role::Assistant,
            new EmbeddedResource(new TextResourceContents('file:///x', 'hello')),
        );

        $rebuilt = PromptMessage::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('provideFromArrayRejectsInvalidInputCases')]
    public function testFromArrayRejectsInvalidInput(array $payload, string $expectedMessage): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage($expectedMessage);

        PromptMessage::fromArray($payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideFromArrayRejectsInvalidInputCases(): iterable
    {
        yield 'missing role' => [
            ['content' => ['type' => 'text', 'text' => 'hi']],
            'PromptMessage data missing "role".',
        ];

        yield 'role not a string' => [
            ['role' => 1, 'content' => ['type' => 'text', 'text' => 'hi']],
            'PromptMessage "role" must be one of [\'user\', \'assistant\'], 1 given.',
        ];

        yield 'unknown role' => [
            ['role' => 'observer', 'content' => ['type' => 'text', 'text' => 'hi']],
            'PromptMessage "role" must be one of [\'user\', \'assistant\'], \'observer\' given.',
        ];

        yield 'missing content' => [
            ['role' => 'user'],
            'PromptMessage data missing "content".',
        ];

        yield 'content not an object' => [
            ['role' => 'user', 'content' => 'oops'],
            'PromptMessage "content" must be an object, string given.',
        ];

        yield 'content list-keyed' => [
            ['role' => 'user', 'content' => ['x']],
            'PromptMessage "content" must be a string-keyed object.',
        ];

        yield 'content missing type' => [
            ['role' => 'user', 'content' => ['text' => 'hi']],
            'PromptMessage content data missing "type".',
        ];

        yield 'content type not a string' => [
            ['role' => 'user', 'content' => ['type' => 1]],
            'PromptMessage content "type" must be a string, int given.',
        ];

        yield 'content type unknown' => [
            ['role' => 'user', 'content' => ['type' => 'unknown']],
            'PromptMessage content "type" must be one of "text", "image", "audio", "resource_link", "resource"; "unknown" given.',
        ];
    }
}
