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

use Nexus\Mcp\Core\Schema\Internal\Request;
use Nexus\Mcp\Core\Schema\Internal\RequestParams;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * A request that expects a response.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic#requests
 *
 * @template-covariant TMethod of non-empty-string
 *
 * @extends Request<TMethod>
 */
abstract readonly class JsonRpcRequest extends Request implements JsonRpcMessage
{
    public function __construct(public RequestId $id, RequestParams $params)
    {
        parent::__construct($params);
    }

    /**
     * @return array{
     *   jsonrpc: '2.0',
     *   id: int|non-empty-string,
     *   method: non-empty-string,
     *   params?: array<string, mixed>,
     * }
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->id->id,
            ...parent::toArray(),
        ];
    }
}
