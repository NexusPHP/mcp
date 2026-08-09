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

namespace Nexus\Mcp\Extension\Tasks\Server\Store;

use Nexus\Mcp\Core\Schema\Request\InputRequest;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;

/**
 * A store-side snapshot of one task.
 */
final readonly class TaskRecord
{
    /**
     * @param non-empty-string                           $taskId
     * @param non-empty-string                           $toolName             The `tools/call` tool the task runs
     * @param non-empty-string                           $createdAt            ISO 8601
     * @param non-empty-string                           $lastUpdatedAt        ISO 8601
     * @param null|array<array-key, mixed>               $arguments            The original call arguments
     * @param null|array<string, mixed>                  $result               The stored result payload, once completed
     * @param null|array<string, mixed>                  $error                The stored error payload, once failed
     * @param array<int|non-empty-string, InputRequest>  $pendingInputRequests Input requests awaiting `tasks/update` answers
     * @param array<int|non-empty-string, InputResponse> $inputResponses       Accumulated answers, re-dispatched with `$requestState`
     * @param null|string                                $requestState         The continuation token the parked result carried
     * @param array<array-key, true>                     $issuedInputKeys      Every input-request key issued over the task's lifetime
     * @param null|non-empty-string                      $statusMessage
     */
    public function __construct(
        public string $taskId,
        public string $toolName,
        public TaskStatus $status,
        public string $createdAt,
        public string $lastUpdatedAt,
        public ?int $ttlMs,
        public int $pollIntervalMs,
        public ?array $arguments = null,
        public ?array $result = null,
        public ?array $error = null,
        public array $pendingInputRequests = [],
        public array $inputResponses = [],
        public ?string $requestState = null,
        public array $issuedInputKeys = [],
        public ?string $statusMessage = null,
    ) {
    }
}
