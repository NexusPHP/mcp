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

namespace Nexus\Mcp\Tests\Extension\Tasks\Schema\ResultResponse;

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Extension\Tasks\Schema\ResultResponse\TaskCallToolResultResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TaskCallToolResultResponse::class)]
#[CoversClass(JsonRpcResultResponse::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class TaskCallToolResultResponseTest extends TestCase
{
    public function testDecodesATaskHandle(): void
    {
        $response = TaskCallToolResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => [
                'resultType' => 'task',
                'taskId' => 'task-1',
                'status' => 'working',
                'createdAt' => '2026-08-04T12:00:00+00:00',
                'lastUpdatedAt' => '2026-08-04T12:00:00+00:00',
                'ttlMs' => 300_000,
                'pollIntervalMs' => 1_000,
            ],
        ]);

        self::assertInstanceOf(CreateTaskResult::class, $response->result);
        self::assertSame('task-1', $response->result->taskId);
    }

    public function testDecodesADirectResult(): void
    {
        $response = TaskCallToolResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => ['resultType' => 'complete', 'content' => []],
        ]);

        self::assertInstanceOf(CallToolResult::class, $response->result);
    }

    public function testDecodesAnInputRequiredResult(): void
    {
        $response = TaskCallToolResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => ['resultType' => 'input_required', 'requestState' => 'tok'],
        ]);

        self::assertInstanceOf(InputRequiredResult::class, $response->result);
    }

    public function testToArrayRoundTripsTheEnvelope(): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => [
                'resultType' => 'task',
                'taskId' => 'task-1',
                'status' => 'working',
                'createdAt' => '2026-08-04T12:00:00+00:00',
                'lastUpdatedAt' => '2026-08-04T12:00:00+00:00',
                'ttlMs' => null,
            ],
        ];

        self::assertSame($payload, TaskCallToolResultResponse::fromArray($payload)->toArray());
    }
}
