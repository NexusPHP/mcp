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

use Nexus\Mcp\Core\Schema\JsonRpc\CompleteResultResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * \@internal.
 *
 * @internal
 */
#[CoversClass(CompleteResultResponse::class)]
#[CoversClass(JsonRpcResultResponse::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CompleteResultResponseTest extends TestCase
{
    public function testRoundTripsTheTypedResult(): void
    {
        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'complete', 'completion' => ['values' => []]]];

        $response = CompleteResultResponse::fromArray($envelope);

        self::assertInstanceOf(CompleteResult::class, $response->result);
        self::assertSame($envelope, $response->toArray());
    }

    public function testRejectsInputRequiredResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result" returned "input_required" for a method that does not support it.');

        CompleteResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['resultType' => 'input_required', 'requestState' => 'tok'],
        ]);
    }
}
