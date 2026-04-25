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
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic#responses
 */
interface JsonRpcResponse extends JsonRpcMessage
{
}
