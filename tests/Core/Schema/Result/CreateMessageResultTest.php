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
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CreateMessageResult;
use Nexus\Mcp\Core\Schema\Sampling\ToolUseContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CreateMessageResult::class)]
#[CoversClass(Result::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CreateMessageResultTest extends TestCase
{
    public function testConstruction(): void
    {
        $result = new CreateMessageResult('claude-3-5-sonnet', Role::Assistant, new TextContent('Hi.'));

        self::assertSame('claude-3-5-sonnet', $result->model);
        self::assertSame(Role::Assistant, $result->role);
        self::assertInstanceOf(TextContent::class, $result->content);
        self::assertNull($result->stopReason);
        self::assertNull($result->meta);
    }

    public function testConstructorRejectsEmptyModel(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageResult model must be a non-empty string.');

        new CreateMessageResult('', Role::Assistant, new TextContent('hi'));
    }

    public function testConstructorRejectsEmptyStopReason(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageResult stopReason must be a non-empty string or null.');

        new CreateMessageResult('claude-3-5-sonnet', Role::Assistant, new TextContent('hi'), stopReason: '');
    }

    public function testToArray(): void
    {
        $result = new CreateMessageResult('claude-3-5-sonnet', Role::Assistant, new TextContent('Hi.'), 'endTurn');

        self::assertSame(
            [
                'model' => 'claude-3-5-sonnet',
                'role' => 'assistant',
                'content' => ['text' => 'Hi.', 'type' => 'text'],
                'stopReason' => 'endTurn',
            ],
            $result->toArray(),
        );
    }

    public function testToArrayWithMeta(): void
    {
        $result = new CreateMessageResult(
            'claude-3-5-sonnet',
            Role::Assistant,
            new TextContent('Hi.'),
            meta: new MetaObject(extras: ['vendor' => 'acme']),
        );

        self::assertSame(
            [
                '_meta' => ['vendor' => 'acme'],
                'model' => 'claude-3-5-sonnet',
                'role' => 'assistant',
                'content' => ['text' => 'Hi.', 'type' => 'text'],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayWithListContent(): void
    {
        $result = new CreateMessageResult(
            'claude-3-5-sonnet',
            Role::Assistant,
            [new TextContent('Step 1.'), new TextContent('Step 2.')],
        );

        self::assertSame(
            [
                'model' => 'claude-3-5-sonnet',
                'role' => 'assistant',
                'content' => [
                    ['text' => 'Step 1.', 'type' => 'text'],
                    ['text' => 'Step 2.', 'type' => 'text'],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testJsonSerializeWrapsEmptyToolUseInput(): void
    {
        $result = new CreateMessageResult(
            'claude-3-5-sonnet',
            Role::Assistant,
            new ToolUseContent('tu-1', 'search', []),
        );

        self::assertStringContainsString('"input":{}', (string) json_encode($result));
    }

    public function testJsonSerializeListContentReturnsArrayOfArrays(): void
    {
        $result = new CreateMessageResult(
            'claude-3-5-sonnet',
            Role::Assistant,
            [new TextContent('Step 1.'), new TextContent('Step 2.')],
        );

        self::assertSame(
            [
                'model' => 'claude-3-5-sonnet',
                'role' => 'assistant',
                'content' => [
                    ['text' => 'Step 1.', 'type' => 'text'],
                    ['text' => 'Step 2.', 'type' => 'text'],
                ],
            ],
            $result->jsonSerialize(),
        );
    }

    public function testFromArrayFullRoundTrip(): void
    {
        $original = new CreateMessageResult('claude-3-5-sonnet', Role::Assistant, new TextContent('Hi.'), 'endTurn');

        $rebuilt = CreateMessageResult::fromArray($original->toArray());

        self::assertSame($original->toArray(), $rebuilt->toArray());
    }

    public function testFromArrayWithListContent(): void
    {
        $rebuilt = CreateMessageResult::fromArray([
            'model' => 'claude-3-5-sonnet',
            'role' => 'assistant',
            'content' => [
                ['text' => 'step 1', 'type' => 'text'],
                ['id' => 'tu-1', 'input' => [], 'name' => 'search', 'type' => 'tool_use'],
            ],
        ]);

        self::assertIsArray($rebuilt->content);
        self::assertCount(2, $rebuilt->content);
    }

    public function testFromArrayRejectsMissingModel(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageResult data missing "model".');

        CreateMessageResult::fromArray(['role' => 'assistant', 'content' => ['text' => 'x', 'type' => 'text']]);
    }

    public function testFromArrayRejectsNonStringModel(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageResult "model" must be a string, int given.');

        CreateMessageResult::fromArray(['model' => 1, 'role' => 'assistant', 'content' => ['text' => 'x', 'type' => 'text']]);
    }

    public function testFromArrayRejectsMissingRole(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageResult data missing "role".');

        CreateMessageResult::fromArray(['model' => 'x', 'content' => ['text' => 'x', 'type' => 'text']]);
    }

    public function testFromArrayRejectsMissingContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageResult data missing "content".');

        CreateMessageResult::fromArray(['model' => 'x', 'role' => 'assistant']);
    }

    public function testFromArrayRejectsNonObjectOrArrayContent(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageResult "content" must be an object or array, string given.');

        CreateMessageResult::fromArray(['model' => 'x', 'role' => 'assistant', 'content' => 'oops']);
    }

    public function testFromArrayRejectsUnknownContentType(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageResult content "type" must be one of "text", "image", "audio", "tool_use", "tool_result"; "resource_link" given.');

        CreateMessageResult::fromArray(['model' => 'x', 'role' => 'assistant', 'content' => ['type' => 'resource_link', 'uri' => 'file:///x', 'name' => 'doc']]);
    }

    public function testFromArrayRejectsNonStringStopReason(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('CreateMessageResult "stopReason" must be a string or null, int given.');

        CreateMessageResult::fromArray(['model' => 'x', 'role' => 'assistant', 'content' => ['text' => 'x', 'type' => 'text'], 'stopReason' => 1]);
    }
}
