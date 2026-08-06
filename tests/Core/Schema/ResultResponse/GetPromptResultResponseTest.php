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
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\ResultResponse\GetPromptResultResponse;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(GetPromptResultResponse::class)]
#[CoversClass(JsonRpcResultResponse::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class GetPromptResultResponseTest extends AbstractMcpTestCase
{
    public function testRoundTripsTheTypedResult(): void
    {
        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'complete', 'messages' => [['content' => ['text' => 'Hello.', 'type' => 'text'], 'role' => 'user']]]];

        $response = GetPromptResultResponse::fromArray($envelope);

        self::assertInstanceOf(GetPromptResult::class, $response->result);
        self::assertSame($envelope, $response->toArray());
    }

    public function testDecodesInputRequiredResult(): void
    {
        $response = GetPromptResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['resultType' => 'input_required', 'requestState' => 'tok'],
        ]);

        self::assertInstanceOf(InputRequiredResult::class, $response->result);
    }
}
