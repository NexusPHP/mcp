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
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\ResultResponse\CallToolResultResponse;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(CallToolResultResponse::class)]
#[CoversClass(JsonRpcResultResponse::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CallToolResultResponseTest extends AbstractMcpTestCase
{
    public function testRoundTripsTheTypedResult(): void
    {
        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'complete', 'content' => []]];

        $response = CallToolResultResponse::fromArray($envelope);

        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertSame($envelope, $response->toArray());
    }

    public function testDecodesInputRequiredResult(): void
    {
        $response = CallToolResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['resultType' => 'input_required', 'requestState' => 'tok'],
        ]);

        self::assertInstanceOf(InputRequiredResult::class, $response->result);
    }

    #[DataProvider('provideRefusesAnUnknownResultTypeCases')]
    public function testRefusesAnUnknownResultType(mixed $resultType): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf(
            '"result.resultType" must be a result type supported by the method, %s given.',
            var_export($resultType, true),
        ));

        CallToolResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['resultType' => $resultType, 'content' => []],
        ]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideRefusesAnUnknownResultTypeCases(): iterable
    {
        yield 'unknown value' => ['bogus'];

        yield 'non-string value' => [1];

        yield 'task, without the tasks extension' => ['task'];
    }
}
