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
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Handler\Request\ListResourcesRequestHandler;
use Nexus\Mcp\Server\Resource\ClosureResourceReader;
use Nexus\Mcp\Server\Resource\ResourceEntry;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ListResourcesRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ListResourcesRequestHandlerTest extends AbstractMcpTestCase
{
    public function testReturnsAllRegisteredResourcesWhenCursorIsNull(): void
    {
        $store = new ResourceStore([
            'file:///a' => new ResourceEntry(new Resource(name: 'a', uri: 'file:///a'), $this->reader()),
            'file:///b' => new ResourceEntry(new Resource(name: 'b', uri: 'file:///b'), $this->reader()),
        ]);
        $handler = new ListResourcesRequestHandler($store);

        $result = $handler->handle(
            new ListResourcesRequest(id: new RequestId(id: 1), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create())),
            $this->makeContext(),
        );

        self::assertCount(2, $result->resources);
        self::assertSame('file:///a', $result->resources[0]->uri);
        self::assertSame('file:///b', $result->resources[1]->uri);
    }

    public function testForwardsCursorToStore(): void
    {
        $store = new ResourceStore(
            [
                'file:///a' => new ResourceEntry(new Resource(name: 'a', uri: 'file:///a'), $this->reader()),
                'file:///b' => new ResourceEntry(new Resource(name: 'b', uri: 'file:///b'), $this->reader()),
                'file:///c' => new ResourceEntry(new Resource(name: 'c', uri: 'file:///c'), $this->reader()),
            ],
            pageSize: 2,
        );
        $handler = new ListResourcesRequestHandler($store);

        $result = $handler->handle(
            new ListResourcesRequest(id: new RequestId(id: 2), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create(), cursor: new Cursor(cursor: 'file:///b'))),
            $this->makeContext(),
        );

        self::assertCount(1, $result->resources);
        self::assertSame('file:///c', $result->resources[0]->uri);
    }

    private function reader(): ClosureResourceReader
    {
        return new ClosureResourceReader(
            static fn(string $uri, ServerContext $context): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
        );
    }

    private function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
