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
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Server\Handler\Request\DiscoverRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(DiscoverRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class DiscoverRequestHandlerTest extends AbstractMcpTestCase
{
    public function testAdvertisesLatestProtocolVersionAndCapabilities(): void
    {
        $capabilities = new ServerCapabilities(tools: ['listChanged' => true]);
        $handler = new DiscoverRequestHandler($capabilities);

        $result = $handler->handle($this->makeRequest(), $this->makeContext());

        self::assertSame([ProtocolVersion::LATEST_VERSION], $result->supportedVersions);
        self::assertSame($capabilities, $result->capabilities);
        self::assertNull($result->instructions);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertNull($result->meta->serverInfo, 'No identity was configured, so none is advertised.');
    }

    public function testAdvertisesTheFullIdentityOnTheResultMeta(): void
    {
        $serverInfo = new Implementation(name: 'test-server', version: '2.0.0', title: 'Test Server');
        $handler = new DiscoverRequestHandler(
            new ServerCapabilities(),
            meta: new GenericResultMetaObject(extras: ['vendor' => 'x']),
            serverInfo: $serverInfo,
        );

        $result = $handler->handle($this->makeRequest(), $this->makeContext());

        self::assertSame($serverInfo, $result->meta->serverInfo);
        self::assertSame(['vendor' => 'x'], $result->meta->extras);
    }

    public function testPropagatesInstructionsTtlCacheScopeAndMeta(): void
    {
        $handler = new DiscoverRequestHandler(
            new ServerCapabilities(),
            'Use the tools wisely.',
            5_000,
            CacheScope::Public,
            new GenericResultMetaObject(extras: ['vendor' => 'x']),
        );

        $result = $handler->handle($this->makeRequest(), $this->makeContext());

        self::assertSame('Use the tools wisely.', $result->instructions);
        self::assertSame(5_000, $result->ttlMs);
        self::assertSame(CacheScope::Public, $result->cacheScope);
        self::assertSame(['vendor' => 'x'], $result->meta->toArray());
    }

    private function makeRequest(): DiscoverRequest
    {
        return new DiscoverRequest(
            id: new RequestId(id: 1),
            params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()),
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
