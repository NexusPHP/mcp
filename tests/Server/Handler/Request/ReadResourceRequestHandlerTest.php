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
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\ReadResourceRequestParams;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\Handler\Request\ReadResourceRequestHandler;
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
#[CoversClass(ReadResourceRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReadResourceRequestHandlerTest extends TestCase
{
    public function testForwardsUriAndContextToStore(): void
    {
        $captured = ['uri' => '', 'requestId' => 0];
        $store = new ResourceStore([
            'file:///a' => new ResourceEntry(
                new Resource('a', 'file:///a'),
                new ClosureResourceReader(static function (string $uri, ServerContext $context) use (&$captured): ReadResourceResult {
                    $captured = ['uri' => $uri, 'requestId' => $context->requestId->id];

                    return new ReadResourceResult([], 0, CacheScope::Private);
                }),
            ),
        ]);
        $handler = new ReadResourceRequestHandler($store);

        $handler->handle(
            new ReadResourceRequest(new RequestId(42), new ReadResourceRequestParams('file:///a', RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        self::assertSame(['uri' => 'file:///a', 'requestId' => 99], $captured);
    }

    public function testReturnsResultFromStoreUnchanged(): void
    {
        $expected = new ReadResourceResult([], 0, CacheScope::Private);
        $store = new ResourceStore([
            'file:///a' => new ResourceEntry(
                new Resource('a', 'file:///a'),
                new ClosureResourceReader(static fn(string $uri, ServerContext $context): ReadResourceResult => $expected),
            ),
        ]);
        $handler = new ReadResourceRequestHandler($store);

        $result = $handler->handle(
            new ReadResourceRequest(new RequestId(1), new ReadResourceRequestParams('file:///a', RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        self::assertSame($expected, $result);
    }

    public function testPropagatesResourceNotFoundFromStore(): void
    {
        $handler = new ReadResourceRequestHandler(new ResourceStore());

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No resource registered under URI "file:\\/\\/\\/missing"\.$/');

        $handler->handle(
            new ReadResourceRequest(new RequestId(1), new ReadResourceRequestParams('file:///missing', RequestMetaObjectFactory::create())),
            self::makeContext(),
        );
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(99),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            null,
            new RecordingSender(),
        );
    }
}
