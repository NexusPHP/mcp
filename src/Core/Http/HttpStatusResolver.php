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
use Nexus\Mcp\Core\Schema\Enum\SdkErrorCode;

/**
 * HTTP status resolver for the JSON-RPC errors the Streamable HTTP transport answers with.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/index#error-codes
 */
final class HttpStatusResolver
{
    /**
     * @param bool $fromHandler Whether the error was produced by a request handler (rides HTTP 200)
     */
    public static function resolve(int $code, bool $fromHandler): int
    {
        if (ProtocolErrorCode::MissingRequiredClientCapability->value === $code) {
            return HttpStatus::BadRequest->value;
        }

        if ($fromHandler) {
            return HttpStatus::Ok->value;
        }

        return match ($code) {
            ProtocolErrorCode::MethodNotFound->value => HttpStatus::NotFound->value,
            SdkErrorCode::Overloaded->value => HttpStatus::ServiceUnavailable->value,
            default => HttpStatus::BadRequest->value,
        };
    }
}
