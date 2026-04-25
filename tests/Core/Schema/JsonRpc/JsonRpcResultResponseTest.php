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

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
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
            ['jsonrpc' => '2.0', 'id' => 42, 'result' => []],
            $response->toArray(),
        );
    }

    public function testToArrayEmitsEnvelopeWithResultMeta(): void
    {
        $response = new JsonRpcResultResponse(
            new RequestId('req-1'),
            new EmptyResult(new Meta(['vendor' => 'x'])),
        );

        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 'req-1',
                'result' => ['_meta' => ['vendor' => 'x']],
            ],
            $response->toArray(),
        );
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $response = new JsonRpcResultResponse(new RequestId(1), new EmptyResult());

        self::assertSame($response->toArray(), $response->jsonSerialize());
    }
}
