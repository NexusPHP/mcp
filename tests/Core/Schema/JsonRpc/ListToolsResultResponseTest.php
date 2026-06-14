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
use Nexus\Mcp\Core\Schema\JsonRpc\ListToolsResultResponse;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * \@internal.
 *
 * @internal
 */
#[CoversClass(ListToolsResultResponse::class)]
#[CoversClass(JsonRpcResultResponse::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListToolsResultResponseTest extends TestCase
{
    public function testRoundTripsTheTypedResult(): void
    {
        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'complete', 'tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private']];

        $response = ListToolsResultResponse::fromArray($envelope);

        self::assertInstanceOf(ListToolsResult::class, $response->result);
        self::assertSame($envelope, $response->toArray());
    }

    public function testRejectsInputRequiredResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result" returned "input_required" for a method that does not support it.');

        ListToolsResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['resultType' => 'input_required', 'requestState' => 'tok'],
        ]);
    }
}
