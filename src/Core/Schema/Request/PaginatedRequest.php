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

namespace Nexus\Mcp\Core\Schema\Request;

use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;

/**
 * A request that paginates its result via an opaque cursor.
 *
 * @property-read PaginatedRequestParams $params
 *
 * @template-covariant TMethod of non-empty-string
 * @template-covariant TEnvelope of array<string, mixed>
 *
 * @extends JsonRpcRequest<TMethod, TEnvelope>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2026-07-28/schema.ts
 */
abstract readonly class PaginatedRequest extends JsonRpcRequest
{
    public function __construct(RequestId $id, PaginatedRequestParams $params)
    {
        parent::__construct(id: $id, params: $params);
    }
}
