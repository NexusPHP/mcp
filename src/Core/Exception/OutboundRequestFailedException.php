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
 * Thrown when a transport cannot carry an outbound request to completion, naming the request whose response
 * can therefore never arrive.
 */
final class OutboundRequestFailedException extends \RuntimeException implements McpExceptionInterface
{
    public function __construct(public readonly RequestId $requestId, \Throwable $previous)
    {
        parent::__construct(
            \sprintf('The exchange carrying request %s failed before a response arrived.', var_export($requestId->id, true)),
            previous: $previous,
        );
    }
}
