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

namespace Nexus\Mcp\Core\Schema\ResultResponse;

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;

/**
 * A successful response from the server for a `resources/templates/list` request.
 *
 * @property-read ListResourceTemplatesResult $result
 *
 * @extends JsonRpcResultResponse<array{
 *   jsonrpc: '2.0',
 *   id: int|non-empty-string,
 *   result: template-type<ListResourceTemplatesResult, Result, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#listresourcetemplatesresultresponse
 */
final readonly class ListResourceTemplatesResultResponse extends JsonRpcResultResponse
{
    public function __construct(RequestId $id, ListResourceTemplatesResult $result)
    {
        parent::__construct(id: $id, result: $result);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $id = self::parseId($data);
        $payload = self::parseResult($data);
        self::rejectInputRequired($payload);

        return new self(id: $id, result: ListResourceTemplatesResult::fromArray($payload));
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
