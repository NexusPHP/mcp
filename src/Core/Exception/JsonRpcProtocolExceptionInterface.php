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

namespace Nexus\Mcp\Core\Exception;

use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;

/**
 * Contract for SDK exceptions that translate to a JSON-RPC error response sent to a peer.
 */
interface JsonRpcProtocolExceptionInterface extends McpExceptionInterface
{
    /**
     * The JSON-RPC error code corresponding to this exception's category.
     */
    public static function getErrorCode(): ProtocolErrorCode;
}
