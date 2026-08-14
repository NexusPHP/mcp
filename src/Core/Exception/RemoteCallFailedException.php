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

use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Core\Schema\Error;

/**
 * Thrown by the outbound-request `await` chain when the peer answers with a `JsonRpcErrorResponse`.
 */
final class RemoteCallFailedException extends \RuntimeException implements McpExceptionInterface
{
    public function __construct(public readonly Error $error, ?\Throwable $previous = null)
    {
        parent::__construct(SafeDisplay::sanitiseCause($error->message), $error->code, $previous);
    }
}
