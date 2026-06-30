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
 * Error codes for protocol errors that are transmitted as JSON-RPC error responses.
 *
 * @see https://www.jsonrpc.org/specification#error_object
 */
enum ProtocolErrorCode: int
{
    /**
     * Invalid JSON was received by the server.
     */
    case ParseError = -32700;

    /**
     * The JSON sent is not a valid Request object.
     */
    case InvalidRequest = -32600;

    /**
     * The method does not exist or is not available.
     */
    case MethodNotFound = -32601;

    /**
     * Invalid method parameter(s).
     */
    case InvalidParams = -32602;

    /**
     * Internal JSON-RPC error.
     */
    case InternalError = -32603;

    /**
     * The HTTP header values do not match the request body, or required headers are missing or malformed.
     */
    case HeaderMismatch = -32020;

    /**
     * Processing the request requires a client capability absent from the request's `clientCapabilities`.
     */
    case MissingRequiredClientCapability = -32021;

    /**
     * The request's protocol version is unknown to the server or unsupported.
     */
    case UnsupportedProtocolVersion = -32022;
}
