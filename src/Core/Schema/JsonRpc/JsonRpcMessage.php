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
 * Refers to any valid JSON-RPC object that can be decoded off the wire, or encoded to be sent.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#jsonrpcmessage
 */
interface JsonRpcMessage extends \JsonSerializable
{
    public const string JSONRPC_VERSION = '2.0';
}
