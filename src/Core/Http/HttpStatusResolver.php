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

namespace Nexus\Mcp\Core\Http;

use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;

/**
 * Resolves the HTTP status the Streamable HTTP transport answers a JSON-RPC error with.
 *
 * A handler-produced error rides HTTP 200 with the error in the body. A transport or protocol error
 * raised before or around dispatch carries a real HTTP status.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/draft/basic/index#error-codes
 */
final class HttpStatusResolver
{
    /**
     * @param bool $fromHandler Whether the error was produced by a request handler (rides HTTP 200)
     */
    public static function resolve(ProtocolErrorCode $code, bool $fromHandler): int
    {
        if ($fromHandler) {
            return HttpStatus::Ok->value;
        }

        return match ($code) {
            ProtocolErrorCode::MethodNotFound => HttpStatus::NotFound->value,
            default => HttpStatus::BadRequest->value,
        };
    }
}
