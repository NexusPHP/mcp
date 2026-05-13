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
 * Thrown when the JSON-RPC envelope is structurally valid JSON but does not
 * conform to the JSON-RPC shape (wrong version, missing fields, malformed response).
 */
final class InvalidRequestException extends AbstractJsonRpcParserException
{
    #[\Override]
    public static function errorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::InvalidRequest;
    }
}
