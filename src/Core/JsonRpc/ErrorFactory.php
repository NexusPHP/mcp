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

namespace Nexus\Mcp\Core\JsonRpc;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error;
use Nexus\Mcp\Core\Schema\Error\HeaderMismatchError;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\InvalidParamsError;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\Error\MethodNotFoundError;
use Nexus\Mcp\Core\Schema\Error\MissingRequiredClientCapabilityError;
use Nexus\Mcp\Core\Schema\Error\ParseError;
use Nexus\Mcp\Core\Schema\Error\UnsupportedProtocolVersionError;

/**
 * Dispatches a `ProtocolErrorCode` to the concrete `Error` subclass for the standard JSON-RPC codes.
 *
 * @internal
 */
final class ErrorFactory
{
    /**
     * @param non-empty-string $message
     */
    public static function create(ProtocolErrorCode $code, string $message, mixed $data = null): Error
    {
        return match ($code) {
            ProtocolErrorCode::ParseError => new ParseError(message: $message, data: $data),
            ProtocolErrorCode::InvalidRequest => new InvalidRequestError(message: $message, data: $data),
            ProtocolErrorCode::MethodNotFound => new MethodNotFoundError(message: $message, data: $data),
            ProtocolErrorCode::InvalidParams => new InvalidParamsError(message: $message, data: $data),
            ProtocolErrorCode::InternalError => new InternalError(message: $message, data: $data),
            ProtocolErrorCode::HeaderMismatch => new HeaderMismatchError(message: $message),
            ProtocolErrorCode::MissingRequiredClientCapability => MissingRequiredClientCapabilityError::fromArray([
                'message' => $message,
                'data' => self::requireData($data, $code, '"requiredCapabilities"'),
            ]),
            ProtocolErrorCode::UnsupportedProtocolVersion => UnsupportedProtocolVersionError::fromArray([
                'message' => $message,
                'data' => self::requireData($data, $code, '"supported" and "requested"'),
            ]),
        };
    }

    /**
     * @param non-empty-string $keys
     */
    private static function requireData(mixed $data, ProtocolErrorCode $code, string $keys): mixed
    {
        Assert::that($data)
            ->not()->isNull(\sprintf('error "data" is required for code %d and must carry %s.', $code->value, $keys))
        ;

        return $data;
    }
}
