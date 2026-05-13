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

use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;

/**
 * A successful (non-error) response to a request.
 *
 * @template-covariant T of Result
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#jsonrpcresultresponse
 */
final readonly class JsonRpcResultResponse implements \JsonSerializable, JsonRpcResponse
{
    /**
     * @param T $result
     */
    public function __construct(public RequestId $id, public Result $result)
    {
    }

    /**
     * @return array{jsonrpc: '2.0', id: int|non-empty-string, result: template-type<T, Arrayable, 'T'>}
     */
    public function toArray(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id->id,
            'result' => $this->result->toArray(),
        ];
    }

    /**
     * @return array{jsonrpc: '2.0', id: int|non-empty-string, result: array<string, mixed>|\stdClass}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id->id,
            'result' => $this->result->jsonSerialize(),
        ];
    }
}
