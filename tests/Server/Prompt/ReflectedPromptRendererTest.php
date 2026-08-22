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
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;
use Nexus\Mcp\Server\Prompt\ReflectedPromptRenderer;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Server\Discovery\ReflectedHandlers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ReflectedPromptRenderer::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReflectedPromptRendererTest extends AbstractMcpTestCase
{
    public function testReturnsGetPromptResultUnchanged(): void
    {
        $result = $this->render('promptResult', null);

        self::assertSame('a description', $result->description);
        self::assertSame(Role::Assistant, $this->firstMessage($result)->role);
    }

    public function testWrapsStringAsUserMessage(): void
    {
        $result = $this->render('promptString', ['topic' => 'AI']);

        self::assertNull($result->description);

        $message = $this->firstMessage($result);
        self::assertSame(Role::User, $message->role);

        self::assertInstanceOf(TextContent::class, $message->content);

        self::assertSame('Write about AI', $message->content->text);
    }

    public function testWrapsSingleMessage(): void
    {
        $result = $this->render('promptMessage', null);

        self::assertCount(1, $result->messages);
        self::assertSame(Role::Assistant, $this->firstMessage($result)->role);
    }

    public function testReturnsMessageList(): void
    {
        $result = $this->render('promptMessageList', null);

        self::assertCount(2, $result->messages);
        self::assertSame(Role::User, $this->firstMessage($result)->role);
    }

    public function testThrowsOnMapOfMessages(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        $this->render('promptMapOfMessages', null);
    }

    public function testThrowsOnEmptyArray(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        $this->render('promptEmpty', null);
    }

    public function testThrowsOnNonMessageList(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);

        $this->render('promptNonMessageList', null);
    }

    public function testThrowsOnUnsupportedReturn(): void
    {
        $this->expectException(UnsupportedReturnValueException::class);
        $this->expectExceptionMessageIs(ReflectedHandlers::class.'::promptUnsupported() must return a '.GetPromptResult::class.', a string, or prompt messages, float given.');

        $this->render('promptUnsupported', null);
    }

    /**
     * @param null|array<string, string> $arguments
     */
    private function render(string $method, ?array $arguments): GetPromptResult
    {
        $renderer = new ReflectedPromptRenderer(new ReflectedHandlers(), new \ReflectionMethod(ReflectedHandlers::class, $method));

        $result = $renderer->render($arguments, $this->makeContext());

        self::assertInstanceOf(GetPromptResult::class, $result);

        return $result;
    }

    private function firstMessage(GetPromptResult $result): PromptMessage
    {
        $message = $result->messages[0] ?? null;

        self::assertInstanceOf(PromptMessage::class, $message);

        return $message;
    }

    private function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
