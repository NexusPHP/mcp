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
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Sampling\SamplingMessage;
use Nexus\Mcp\Core\Schema\Sampling\ToolUseContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SamplingMessage::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SamplingMessageTest extends TestCase
{
    public function testConstructionWithSingleContent(): void
    {
        $msg = new SamplingMessage(Role::User, new TextContent('hi'));

        self::assertSame(Role::User, $msg->role);
        self::assertInstanceOf(TextContent::class, $msg->content);
    }

    public function testConstructionWithListContent(): void
    {
        $blocks = [new TextContent('step 1'), new ToolUseContent('tu-1', 'search', [])];
        $msg = new SamplingMessage(Role::Assistant, $blocks);

        self::assertSame($blocks, $msg->content);
    }

    public function testConstructorRejectsNonContentBlockEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Value "\'not-a-block\'" in iterable is expected to be an instance of \'Nexus\\\\Mcp\\\\Core\\\\Schema\\\\Sampling\\\\SamplingMessageContentBlock\' but got string instead.');

        new SamplingMessage(Role::User, ['not-a-block']); // @phpstan-ignore argument.type
    }

    public function testToArrayWithSingleContent(): void
    {
        $msg = new SamplingMessage(Role::User, new TextContent('hi'));

        self::assertSame(
            ['role' => 'user', 'content' => ['text' => 'hi', 'type' => 'text']],
            $msg->toArray(),
        );
    }

    public function testToArrayWithListContent(): void
    {
        $msg = new SamplingMessage(Role::Assistant, [new TextContent('step 1')]);

        self::assertSame(
            ['role' => 'assistant', 'content' => [['text' => 'step 1', 'type' => 'text']]],
            $msg->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $msg = new SamplingMessage(Role::User, new TextContent('hi'), new MetaObject(extras: ['trace' => 'abc']));

        self::assertSame(
            ['role' => 'user', 'content' => ['text' => 'hi', 'type' => 'text'], '_meta' => ['trace' => 'abc']],
            $msg->toArray(),
        );
    }

    public function testToArrayOmitsEmptyMeta(): void
    {
        $msg = new SamplingMessage(Role::User, new TextContent('hi'), new MetaObject());

        self::assertArrayNotHasKey('_meta', $msg->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $msg = new SamplingMessage(Role::User, new TextContent('hi'));

        self::assertSame($msg->toArray(), $msg->jsonSerialize());
    }

    public function testFromArrayWithSingleContent(): void
    {
        $msg = SamplingMessage::fromArray([
            'role' => 'user',
            'content' => ['text' => 'hi', 'type' => 'text'],
        ]);

        self::assertSame(Role::User, $msg->role);
        self::assertInstanceOf(TextContent::class, $msg->content);
    }

    public function testFromArrayWithListContent(): void
    {
        $msg = SamplingMessage::fromArray([
            'role' => 'assistant',
            'content' => [
                ['text' => 'step 1', 'type' => 'text'],
                ['id' => 'tu-1', 'input' => [], 'name' => 'search', 'type' => 'tool_use'],
            ],
        ]);

        self::assertIsArray($msg->content);
        self::assertCount(2, $msg->content);
    }

    public function testFromArrayWithEmptyListContent(): void
    {
        $msg = SamplingMessage::fromArray([
            'role' => 'user',
            'content' => [],
        ]);

        self::assertSame([], $msg->content);
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new SamplingMessage(Role::User, new TextContent('hi'));

        $rebuilt = SamplingMessage::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayRejectsMissingRole(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('SamplingMessage data missing "role".');

        SamplingMessage::fromArray(['content' => ['text' => 'x', 'type' => 'text']]);
    }

    public function testFromArrayRejectsNonStringRole(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('SamplingMessage "role" must be one of [\'user\', \'assistant\'], 1 given.');

        SamplingMessage::fromArray(['role' => 1, 'content' => ['text' => 'x', 'type' => 'text']]);
    }

    public function testFromArrayRejectsUnknownRole(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('SamplingMessage "role" must be one of [\'user\', \'assistant\'], \'observer\' given.');

        SamplingMessage::fromArray(['role' => 'observer', 'content' => ['text' => 'x', 'type' => 'text']]);
    }

    public function testFromArrayRejectsMissingContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('SamplingMessage data missing "content".');

        SamplingMessage::fromArray(['role' => 'user']);
    }

    public function testFromArrayRejectsNonObjectContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('SamplingMessage "content" must be an object or array, string given.');

        SamplingMessage::fromArray(['role' => 'user', 'content' => 'oops']);
    }

    public function testFromArrayRejectsNonObjectContentEntry(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('SamplingMessage content entry must be an object, string given.');

        SamplingMessage::fromArray(['role' => 'user', 'content' => ['oops']]);
    }

    public function testFromArrayRejectsUnknownContentType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('SamplingMessage content "type" must be one of "text", "image", "audio", "tool_use", "tool_result"; "resource_link" given.');

        SamplingMessage::fromArray(['role' => 'user', 'content' => ['type' => 'resource_link', 'uri' => 'file:///x', 'name' => 'doc']]);
    }

    public function testJsonSerializeListContentReturnsArrayOfArrays(): void
    {
        $msg = new SamplingMessage(Role::Assistant, [new TextContent('one'), new TextContent('two')]);

        self::assertSame(
            [
                'role' => 'assistant',
                'content' => [
                    ['text' => 'one', 'type' => 'text'],
                    ['text' => 'two', 'type' => 'text'],
                ],
            ],
            $msg->jsonSerialize(),
        );
    }
}
