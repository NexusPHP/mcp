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

/**
 * Thrown when a transport operation is invoked on a closed transport.
 */
final class TransportAlreadyClosedException extends \LogicException implements McpExceptionInterface
{
    /**
     * @param non-empty-string $operation
     */
    public function __construct(string $operation, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('Cannot %s on a closed transport.', $operation), previous: $previous);
    }
}
