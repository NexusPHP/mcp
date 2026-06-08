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

namespace Nexus\Mcp\Tests\Core\Schema\JsonRpc;

use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(JsonRpcResultResponse::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class JsonRpcResultResponseTest extends TestCase
{
    public function testToArrayEmitsEnvelopeWithEmptyResult(): void
    {
        $response = new JsonRpcResultResponse(new RequestId(42), new EmptyResult());

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 42, 'result' => ['resultType' => 'complete']],
            $response->toArray(),
        );
    }

    public function testToArrayEmitsEnvelopeWithResultMeta(): void
    {
        $response = new JsonRpcResultResponse(
            new RequestId('req-1'),
            new EmptyResult(new MetaObject(['vendor' => 'x'])),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 'req-1',
                'result' => ['_meta' => ['vendor' => 'x'], 'resultType' => 'complete'],
            ],
            $response->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArrayForLeafResult(): void
    {
        $response = new JsonRpcResultResponse(new RequestId(1), new EmptyResult(new MetaObject(['vendor' => 'x'])));

        self::assertSame($response->toArray(), $response->jsonSerialize());
    }

    public function testJsonSerializePreservesEmptyObjectMarkersFromInnerResult(): void
    {
        $response = new JsonRpcResultResponse(
            new RequestId(99),
            new DiscoverResult(
                [ProtocolVersion::LATEST_VERSION],
                new ServerCapabilities(logging: []),
                new Implementation('srv', '1.0.0'),
                0,
                CacheScope::Private,
            ),
        );

        $encoded = json_encode($response);

        self::assertIsString($encoded);
        self::assertStringContainsString('"logging":{}', $encoded, 'Empty capability markers must encode as JSON objects, not arrays.');
    }
}
