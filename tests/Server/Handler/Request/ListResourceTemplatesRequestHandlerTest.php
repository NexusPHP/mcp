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
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Server\Handler\Request\ListResourceTemplatesRequestHandler;
use Nexus\Mcp\Server\Resource\ResourceTemplateStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
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
            'alpha' => new ResourceTemplate('alpha', 'file:///{x}.alpha'),
            'beta' => new ResourceTemplate('beta', 'file:///{x}.beta'),
        ]);
        $handler = new ListResourceTemplatesRequestHandler($store);

        $result = $handler->handle(
            new ListResourceTemplatesRequest(new RequestId(1), new PaginatedRequestParams()),
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
                'a' => new ResourceTemplate('a', 'file:///{x}.a'),
                'b' => new ResourceTemplate('b', 'file:///{x}.b'),
                'c' => new ResourceTemplate('c', 'file:///{x}.c'),
            ],
            pageSize: 2,
        );
        $handler = new ListResourceTemplatesRequestHandler($store);

        $result = $handler->handle(
            new ListResourceTemplatesRequest(new RequestId(2), new PaginatedRequestParams(new Cursor('b'))),
            self::makeContext(),
        );

        self::assertCount(1, $result->resourceTemplates);
        self::assertSame('c', $result->resourceTemplates[0]->name);
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
