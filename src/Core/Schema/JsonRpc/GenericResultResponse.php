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

namespace Nexus\Mcp\Core\Schema\JsonRpc;

use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;

/**
 * Result-response envelope for results with no dedicated typed response. The
 * dispatchers use it to send such results (e.g. `EmptyResult`), and `fromArray`
 * decodes a bare success response into an `EmptyResult`.
 *
 * @internal
 *
 * @extends JsonRpcResultResponse<array{
 *   jsonrpc: '2.0',
 *   id: int|non-empty-string,
 *   result: template-type<Result<array<string, mixed>>, Result, 'T'>,
 * }>
 */
final readonly class GenericResultResponse extends JsonRpcResultResponse
{
    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(id: self::parseId($data), result: EmptyResult::fromArray(self::parseResult($data)));
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
