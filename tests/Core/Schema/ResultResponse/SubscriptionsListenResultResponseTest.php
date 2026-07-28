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

namespace Nexus\Mcp\Tests\Core\Schema\ResultResponse;

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\MetaObject\SubscriptionsListenResultMetaObject;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\SubscriptionsListenResult;
use Nexus\Mcp\Core\Schema\ResultResponse\SubscriptionsListenResultResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SubscriptionsListenResultResponse::class)]
#[CoversClass(JsonRpcResultResponse::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SubscriptionsListenResultResponseTest extends TestCase
{
    public function testRoundTripsTheTypedResult(): void
    {
        $envelope = [
            'jsonrpc' => '2.0',
            'id' => 42,
            'result' => [
                '_meta' => ['io.modelcontextprotocol/subscriptionId' => 42],
                'resultType' => 'complete',
            ],
        ];

        $response = SubscriptionsListenResultResponse::fromArray($envelope);

        self::assertInstanceOf(SubscriptionsListenResult::class, $response->result);
        self::assertSame($envelope, $response->toArray());
    }

    public function testCarriesTheIdItWasBuiltWith(): void
    {
        $response = new SubscriptionsListenResultResponse(
            id: new RequestId('stream-1'),
            result: new SubscriptionsListenResult(
                new SubscriptionsListenResultMetaObject(subscriptionId: new RequestId('stream-1')),
            ),
        );

        self::assertSame('stream-1', $response->id->id);
        self::assertSame('stream-1', $response->toArray()['id']);
    }

    public function testRejectsInputRequiredResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result" returned "input_required" for a method that does not support it.');

        SubscriptionsListenResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['resultType' => 'input_required', 'requestState' => 'tok'],
        ]);
    }
}
