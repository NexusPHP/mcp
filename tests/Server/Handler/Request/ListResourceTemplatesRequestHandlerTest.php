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
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Server\Handler\Request\ListResourceTemplatesRequestHandler;
use Nexus\Mcp\Server\Resource\ClosureTemplatedResourceReader;
use Nexus\Mcp\Server\Resource\ResourceTemplateEntry;
use Nexus\Mcp\Server\Resource\ResourceTemplateStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ListResourceTemplatesRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ListResourceTemplatesRequestHandlerTest extends TestCase
{
    public function testReturnsAllRegisteredTemplatesWhenCursorIsNull(): void
    {
        $store = new ResourceTemplateStore([
            'file:///{x}.alpha' => self::entry(new ResourceTemplate(name: 'alpha', uriTemplate: 'file:///{x}.alpha')),
            'file:///{x}.beta' => self::entry(new ResourceTemplate(name: 'beta', uriTemplate: 'file:///{x}.beta')),
        ]);
        $handler = new ListResourceTemplatesRequestHandler($store);

        $result = $handler->handle(
            new ListResourceTemplatesRequest(id: new RequestId(id: 1), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        self::assertCount(2, $result->resourceTemplates);
        self::assertSame('alpha', $result->resourceTemplates[0]->name);
        self::assertSame('beta', $result->resourceTemplates[1]->name);
    }

    public function testForwardsCursorToStore(): void
    {
        $store = new ResourceTemplateStore(
            [
                'file:///{x}.a' => self::entry(new ResourceTemplate(name: 'a', uriTemplate: 'file:///{x}.a')),
                'file:///{x}.b' => self::entry(new ResourceTemplate(name: 'b', uriTemplate: 'file:///{x}.b')),
                'file:///{x}.c' => self::entry(new ResourceTemplate(name: 'c', uriTemplate: 'file:///{x}.c')),
            ],
            pageSize: 2,
        );
        $handler = new ListResourceTemplatesRequestHandler($store);

        $result = $handler->handle(
            new ListResourceTemplatesRequest(id: new RequestId(id: 2), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create(), cursor: new Cursor(cursor: 'file:///{x}.b'))),
            self::makeContext(),
        );

        self::assertCount(1, $result->resourceTemplates);
        self::assertSame('c', $result->resourceTemplates[0]->name);
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            null,
            new RecordingSender(),
        );
    }

    private static function entry(ResourceTemplate $template): ResourceTemplateEntry
    {
        return new ResourceTemplateEntry(
            $template,
            new ClosureTemplatedResourceReader(
                static fn(): never => throw new \LogicException('unreachable'),
            ),
        );
    }
}
