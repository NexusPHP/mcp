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
 * Error codes for protocol errors that cross the wire as JSON-RPC error responses.
 *
 * @see https://www.jsonrpc.org/specification#error_object
 */
enum ProtocolErrorCode: int
{
    case ParseError = -32700;
    case InvalidRequest = -32600;
    case MethodNotFound = -32601;
    case InvalidParams = -32602;
    case InternalError = -32603;
    case UrlElicitationRequired = -32042;
}
