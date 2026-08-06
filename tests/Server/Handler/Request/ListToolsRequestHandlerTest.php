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
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Handler\Request\ListToolsRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ListToolsRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ListToolsRequestHandlerTest extends AbstractMcpTestCase
{
    public function testReturnsAllRegisteredToolsWhenCursorIsNull(): void
    {
        $store = new ToolStore([
            'alpha' => new ToolEntry(new Tool(name: 'alpha', inputSchema: ['type' => 'object']), self::executor()),
            'beta' => new ToolEntry(new Tool(name: 'beta', inputSchema: ['type' => 'object']), self::executor()),
        ]);
        $handler = new ListToolsRequestHandler($store);

        $result = $handler->handle(
            new ListToolsRequest(id: new RequestId(id: 1), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        self::assertCount(2, $result->tools);
        self::assertSame('alpha', $result->tools[0]->name);
        self::assertSame('beta', $result->tools[1]->name);
    }

    public function testForwardsCursorToStore(): void
    {
        $store = new ToolStore(
            [
                'a' => new ToolEntry(new Tool(name: 'a', inputSchema: ['type' => 'object']), self::executor()),
                'b' => new ToolEntry(new Tool(name: 'b', inputSchema: ['type' => 'object']), self::executor()),
                'c' => new ToolEntry(new Tool(name: 'c', inputSchema: ['type' => 'object']), self::executor()),
            ],
            pageSize: 2,
        );
        $handler = new ListToolsRequestHandler($store);

        $result = $handler->handle(
            new ListToolsRequest(id: new RequestId(id: 2), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create(), cursor: new Cursor(cursor: 'b'))),
            self::makeContext(),
        );

        self::assertCount(1, $result->tools);
        self::assertSame('c', $result->tools[0]->name);
    }

    private static function executor(): ClosureToolExecutor
    {
        return new ClosureToolExecutor(
            static fn(?array $arguments, ServerContext $context): CallToolResult => new CallToolResult(content: []),
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
