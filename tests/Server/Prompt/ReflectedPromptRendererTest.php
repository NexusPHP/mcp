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

namespace Nexus\Mcp\Tests\Server\Prompt;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\Prompt\PromptMessage;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;
use Nexus\Mcp\Server\Prompt\ReflectedPromptRenderer;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ReflectedPromptRenderer::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReflectedPromptRendererTest extends TestCase
{
    public function testReturnsGetPromptResultUnchanged(): void
    {
        $result = self::render('promptResult', null);

        self::assertSame('a description', $result->description);
        self::assertSame(Role::Assistant, self::firstMessage($result)->role);
    }

    public function testWrapsStringAsUserMessage(): void
    {
        $result = self::render('promptString', ['topic' => 'AI']);

        self::assertNull($result->description);

        $message = self::firstMessage($result);
        self::assertSame(Role::User, $message->role);

        if (! $message->content instanceof TextContent) {
            self::fail('Expected a TextContent message.');
        }

        self::assertSame('Write about AI', $message->content->text);
    }

    public function testWrapsSingleMessage(): void
    {
        $result = self::render('promptMessage', null);

        self::assertCount(1, $result->messages);
        self::assertSame(Role::Assistant, self::firstMessage($result)->role);
    }

    public function testReturnsMessageList(): void
    {
        $result = self::render('promptMessageList', null);

        self::assertCount(2, $result->messages);
        self::assertSame(Role::User, self::firstMessage($result)->role);
    }

    public function testThrowsOnMapOfMessages(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        self::render('promptMapOfMessages', null);
    }

    public function testThrowsOnEmptyArray(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        self::render('promptEmpty', null);
    }

    public function testThrowsOnNonMessageList(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        self::render('promptNonMessageList', null);
    }

    public function testThrowsOnUnsupportedReturn(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessageIs(ReflectedHandlers::class.'::promptUnsupported() must return a '.GetPromptResult::class.', a string, or prompt messages, float given.');

        self::render('promptUnsupported', null);
    }

    /**
     * @param null|array<string, string> $arguments
     */
    private static function render(string $method, ?array $arguments): GetPromptResult
    {
        $renderer = new ReflectedPromptRenderer(new ReflectedHandlers(), new \ReflectionMethod(ReflectedHandlers::class, $method));

        return $renderer->render($arguments, self::makeContext());
    }

    private static function firstMessage(GetPromptResult $result): PromptMessage
    {
        $message = $result->messages[0] ?? null;

        if (! $message instanceof PromptMessage) {
            self::fail('Expected a PromptMessage.');
        }

        return $message;
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(7),
            new NullCancellation(),
            new RequestMetaObject(),
            'session-1',
            new RecordingSender(),
        );
    }
}
