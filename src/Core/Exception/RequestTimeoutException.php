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

use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Thrown when an outbound request's deadline elapses before the peer answers it.
 */
final class RequestTimeoutException extends \RuntimeException implements McpExceptionInterface
{
    public function __construct(
        public readonly RequestId $requestId,
        float $seconds,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Request %s went unanswered for %s seconds.', var_export($requestId->id, true), $seconds),
            previous: $previous,
        );
    }
}
