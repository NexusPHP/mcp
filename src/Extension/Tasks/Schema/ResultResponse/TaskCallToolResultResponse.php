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

namespace Nexus\Mcp\Extension\Tasks\Schema\ResultResponse;

use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;

/**
 * Response envelope for a task-aware `tools/call`: the result decodes on its
 * `resultType` into the direct result, an input request, or a task handle.
 *
 * @property-read CallToolResult|CreateTaskResult|InputRequiredResult $result
 *
 * @extends JsonRpcResultResponse<array{
 *   jsonrpc: '2.0',
 *   id: int|non-empty-string,
 *   result: array<string, mixed>,
 * }>
 */
final readonly class TaskCallToolResultResponse extends JsonRpcResultResponse
{
    public function __construct(RequestId $id, CallToolResult|CreateTaskResult|InputRequiredResult $result)
    {
        parent::__construct(id: $id, result: $result);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = self::parseId($data);
        $payload = self::parseResult($data);

        $result = match (true) {
            ($payload['resultType'] ?? null) === ResultType::Task->value => CreateTaskResult::fromArray($payload),
            self::isInputRequired($payload) => InputRequiredResult::fromArray($payload),
            default => CallToolResult::fromArray($payload),
        };

        return new self(id: $id, result: $result);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id->id,
            'result' => $this->result->toArray(),
        ];
    }
}
