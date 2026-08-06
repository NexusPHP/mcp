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

namespace Nexus\Mcp\Core\Schema\Enum;

/**
 * JSON-RPC error codes this SDK allocates for itself, from the `-32000` to `-32019` range MCP
 * partitions for implementation-defined use. The specification never defines codes there, and a
 * receiver must not read cross-implementation meaning into them.
 *
 * The counterpart holding spec-defined codes is `ProtocolErrorCode`.
 *
 * @see https://www.jsonrpc.org/specification#error_object
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/index#error-codes
 */
enum SdkErrorCode: int
{
    /**
     * The server is already dispatching as many messages as it accepts at once.
     * The request was not processed and may be retried.
     */
    case Overloaded = -32_000;
}
