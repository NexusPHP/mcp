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
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Thrown when an inbound-request handler invokes the `SenderInterface::sendRequest()`
 * stub before the corresponding outbound-request machinery has been implemented
 * for the dispatching side.
 */
final class OutboundRequestsNotSupportedException extends AbstractJsonRpcProtocolException
{
    public function __construct(?RequestId $requestId = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            $requestId,
            'Outbound server-to-client requests are not implemented yet.',
            $previous,
        );
    }

    #[\Override]
    public static function errorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::InternalError;
    }
}
