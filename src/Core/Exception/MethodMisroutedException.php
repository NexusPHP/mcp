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

use Nexus\Mcp\Core\JsonRpc\SafeDisplay;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Thrown when an inbound envelope's shape (request or notification) does not
 * match the shape the method was registered under.
 */
final class MethodMisroutedException extends AbstractJsonRpcProtocolException
{
    /**
     * @param non-empty-string $method
     * @param non-empty-string $expectedShape
     * @param non-empty-string $receivedShape
     */
    public function __construct(
        string $method,
        string $expectedShape,
        string $receivedShape,
        ?RequestId $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $requestId,
            \sprintf(
                'Method "%s" must be sent as a %s, not a %s.',
                SafeDisplay::sanitise($method),
                $expectedShape,
                $receivedShape,
            ),
            $previous,
        );
    }

    #[\Override]
    public static function errorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::InvalidRequest;
    }
}
