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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Handler\Request\ListToolsRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListToolsRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ListToolsRequestHandlerTest extends TestCase
{
    public function testReturnsAllRegisteredToolsWhenCursorIsNull(): void
    {
        $store = new ToolStore([
            'alpha' => ['tool' => new Tool('alpha', ['type' => 'object']), 'executor' => self::executor()],
            'beta' => ['tool' => new Tool('beta', ['type' => 'object']), 'executor' => self::executor()],
        ]);
        $handler = new ListToolsRequestHandler($store);

        $result = $handler->handle(
            new ListToolsRequest(new RequestId(1), new PaginatedRequestParams()),
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
                'a' => ['tool' => new Tool('a', ['type' => 'object']), 'executor' => self::executor()],
                'b' => ['tool' => new Tool('b', ['type' => 'object']), 'executor' => self::executor()],
                'c' => ['tool' => new Tool('c', ['type' => 'object']), 'executor' => self::executor()],
            ],
            pageSize: 2,
        );
        $handler = new ListToolsRequestHandler($store);

        $result = $handler->handle(
            new ListToolsRequest(new RequestId(2), new PaginatedRequestParams(new Cursor('b'))),
            self::makeContext(),
        );

        self::assertCount(1, $result->tools);
        self::assertSame('c', $result->tools[0]->name);
    }

    private static function executor(): ClosureToolExecutor
    {
        return new ClosureToolExecutor(
            static fn(?array $arguments, ServerContext $context): CallToolResult => new CallToolResult([]),
        );
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            new RequestMetaObject(),
            null,
            new RecordingSender(),
        );
    }
}
