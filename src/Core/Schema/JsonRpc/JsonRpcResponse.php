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

/**
 * A response to a request, containing either the result or error.
 *
 * @phpstan-sealed JsonRpcResultResponse|JsonRpcErrorResponse
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#jsonrpcresponse
 */
interface JsonRpcResponse extends JsonRpcMessage
{
}
