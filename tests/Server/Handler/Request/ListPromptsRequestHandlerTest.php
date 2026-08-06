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

namespace Nexus\Mcp\Tests\Server\Handler\Request;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Handler\Request\ListPromptsRequestHandler;
use Nexus\Mcp\Server\Prompt\ClosurePromptRenderer;
use Nexus\Mcp\Server\Prompt\PromptEntry;
use Nexus\Mcp\Server\Prompt\PromptStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ListPromptsRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ListPromptsRequestHandlerTest extends AbstractMcpTestCase
{
    public function testReturnsAllRegisteredPromptsWhenCursorIsNull(): void
    {
        $store = new PromptStore([
            'alpha' => new PromptEntry(new Prompt(name: 'alpha'), self::renderer()),
            'beta' => new PromptEntry(new Prompt(name: 'beta'), self::renderer()),
        ]);
        $handler = new ListPromptsRequestHandler($store);

        $result = $handler->handle(
            new ListPromptsRequest(id: new RequestId(id: 1), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        self::assertCount(2, $result->prompts);
        self::assertSame('alpha', $result->prompts[0]->name);
        self::assertSame('beta', $result->prompts[1]->name);
    }

    public function testForwardsCursorToStore(): void
    {
        $store = new PromptStore(
            [
                'a' => new PromptEntry(new Prompt(name: 'a'), self::renderer()),
                'b' => new PromptEntry(new Prompt(name: 'b'), self::renderer()),
                'c' => new PromptEntry(new Prompt(name: 'c'), self::renderer()),
            ],
            pageSize: 2,
        );
        $handler = new ListPromptsRequestHandler($store);

        $result = $handler->handle(
            new ListPromptsRequest(id: new RequestId(id: 2), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create(), cursor: new Cursor(cursor: 'b'))),
            self::makeContext(),
        );

        self::assertCount(1, $result->prompts);
        self::assertSame('c', $result->prompts[0]->name);
    }

    private static function renderer(): ClosurePromptRenderer
    {
        return new ClosurePromptRenderer(
            static fn(?array $arguments, ServerContext $context): GetPromptResult => new GetPromptResult(messages: []),
        );
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
