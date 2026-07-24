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
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\ResultMetaObject;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Server\Handler\Request\DiscoverRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DiscoverRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class DiscoverRequestHandlerTest extends TestCase
{
    public function testAdvertisesLatestProtocolVersionCapabilitiesAndServerInfo(): void
    {
        $serverInfo = new Implementation(name: 'test-server', version: '2.0.0');
        $capabilities = new ServerCapabilities(tools: ['listChanged' => true]);
        $handler = new DiscoverRequestHandler($serverInfo, $capabilities);

        $result = $handler->handle(self::makeRequest(), self::makeContext());

        self::assertSame([ProtocolVersion::LATEST_VERSION], $result->supportedVersions);
        self::assertSame($capabilities, $result->capabilities);
        self::assertSame($serverInfo, $result->meta->serverInfo);
        self::assertNull($result->instructions);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
        self::assertSame(
            [ResultMetaObject::SERVER_INFO_KEY => ['name' => 'test-server', 'version' => '2.0.0']],
            $result->meta->toArray(),
        );
    }

    public function testPropagatesInstructionsTtlCacheScopeAndMeta(): void
    {
        $serverInfo = new Implementation(name: 'test-server', version: '2.0.0');
        $handler = new DiscoverRequestHandler(
            $serverInfo,
            new ServerCapabilities(),
            'Use the tools wisely.',
            5_000,
            CacheScope::Public,
            new ResultMetaObject(extras: ['vendor' => 'x']),
        );

        $result = $handler->handle(self::makeRequest(), self::makeContext());

        self::assertSame('Use the tools wisely.', $result->instructions);
        self::assertSame(5_000, $result->ttlMs);
        self::assertSame(CacheScope::Public, $result->cacheScope);
        self::assertSame($serverInfo, $result->meta->serverInfo);
        self::assertSame(
            [
                ResultMetaObject::SERVER_INFO_KEY => ['name' => 'test-server', 'version' => '2.0.0'],
                'vendor' => 'x',
            ],
            $result->meta->toArray(),
        );
    }

    private static function makeRequest(): DiscoverRequest
    {
        return new DiscoverRequest(
            id: new RequestId(id: 1),
            params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()),
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
