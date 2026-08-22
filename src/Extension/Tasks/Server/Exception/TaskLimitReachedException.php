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

namespace Nexus\Mcp\Extension\Tasks\Server\Exception;

use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Thrown when a request would start a task while the server already runs its maximum number of tasks.
 */
final class TaskLimitReachedException extends AbstractJsonRpcProtocolException
{
    public function __construct(int $limit, ?RequestId $requestId = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            $requestId,
            \sprintf('Task limit reached: this server runs at most %d tasks at once.', $limit),
            $previous,
            errorData: ['limit' => $limit],
        );
    }

    #[\Override]
    public static function getErrorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::InternalError;
    }
}
