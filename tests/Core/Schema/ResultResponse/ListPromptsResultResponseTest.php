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
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Core\Schema\ResultResponse\ListPromptsResultResponse;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * \@internal.
 *
 * @internal
 */
#[CoversClass(ListPromptsResultResponse::class)]
#[CoversClass(JsonRpcResultResponse::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListPromptsResultResponseTest extends AbstractMcpTestCase
{
    public function testRoundTripsTheTypedResult(): void
    {
        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'complete', 'prompts' => [['name' => 'code-review']], 'ttlMs' => 0, 'cacheScope' => 'private']];

        $response = ListPromptsResultResponse::fromArray($envelope);

        self::assertInstanceOf(ListPromptsResult::class, $response->result);
        self::assertSame($envelope, $response->toArray());
    }

    public function testRejectsInputRequiredResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result" returned "input_required" for a method that does not support it.');

        ListPromptsResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['resultType' => 'input_required', 'requestState' => 'tok'],
        ]);
    }
}
