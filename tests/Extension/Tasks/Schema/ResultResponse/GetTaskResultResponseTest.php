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
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\ResultResponse\GetTaskResultResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(GetTaskResultResponse::class)]
#[CoversClass(JsonRpcResultResponse::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class GetTaskResultResponseTest extends TestCase
{
    public function testDecodesTheTaskState(): void
    {
        $response = GetTaskResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => [
                'resultType' => 'complete',
                'taskId' => 'task-1',
                'status' => 'completed',
                'createdAt' => '2026-08-04T12:00:00+00:00',
                'lastUpdatedAt' => '2026-08-04T12:00:05+00:00',
                'ttlMs' => 300_000,
                'result' => ['resultType' => 'complete', 'content' => []],
            ],
        ]);

        self::assertSame(TaskStatus::Completed, $response->result->status);
        self::assertSame('task-1', $response->result->taskId);
    }

    public function testToArrayRoundTripsTheEnvelope(): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => [
                'resultType' => 'complete',
                'taskId' => 'task-1',
                'status' => 'working',
                'createdAt' => '2026-08-04T12:00:00+00:00',
                'lastUpdatedAt' => '2026-08-04T12:00:00+00:00',
                'ttlMs' => null,
            ],
        ];

        self::assertSame($payload, GetTaskResultResponse::fromArray($payload)->toArray());
    }

    public function testRejectsAnInputRequiredEnvelope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"result" returned "input_required" for a method that does not support it.');

        GetTaskResultResponse::fromArray([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => ['resultType' => 'input_required', 'requestState' => 'tok'],
        ]);
    }
}
