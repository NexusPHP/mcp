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
 * Thrown when the parser cannot resolve an inbound `method` to a registered
 * request or notification class.
 */
final class MethodNotFoundException extends AbstractJsonRpcParserException
{
    public function __construct(
        public readonly string $method,
        null|int|string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('No class registered for method "%s".', $method),
            $requestId,
            $previous,
        );
    }

    #[\Override]
    public static function errorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::MethodNotFound;
    }
}
