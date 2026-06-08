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
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListResourcesRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ListResourcesRequestHandlerTest extends TestCase
{
    public function testReturnsAllRegisteredResourcesWhenCursorIsNull(): void
    {
        $store = new ResourceStore([
            'file:///a' => new ResourceEntry(new Resource('a', 'file:///a'), self::reader()),
            'file:///b' => new ResourceEntry(new Resource('b', 'file:///b'), self::reader()),
        ]);
        $handler = new ListResourcesRequestHandler($store);

        $result = $handler->handle(
            new ListResourcesRequest(new RequestId(1), new PaginatedRequestParams(RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        self::assertCount(2, $result->resources);
        self::assertSame('file:///a', $result->resources[0]->uri);
        self::assertSame('file:///b', $result->resources[1]->uri);
    }

    public function testForwardsCursorToStore(): void
    {
        $store = new ResourceStore(
            [
                'file:///a' => new ResourceEntry(new Resource('a', 'file:///a'), self::reader()),
                'file:///b' => new ResourceEntry(new Resource('b', 'file:///b'), self::reader()),
                'file:///c' => new ResourceEntry(new Resource('c', 'file:///c'), self::reader()),
            ],
            pageSize: 2,
        );
        $handler = new ListResourcesRequestHandler($store);

        $result = $handler->handle(
            new ListResourcesRequest(new RequestId(2), new PaginatedRequestParams(RequestMetaObjectFactory::create(), new Cursor('file:///b'))),
            self::makeContext(),
        );

        self::assertCount(1, $result->resources);
        self::assertSame('file:///c', $result->resources[0]->uri);
    }

    private static function reader(): ClosureResourceReader
    {
        return new ClosureResourceReader(
            static fn(string $uri, ServerContext $context): ReadResourceResult => new ReadResourceResult([], 0, CacheScope::Private),
        );
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            null,
            new RecordingSender(),
        );
    }
}
