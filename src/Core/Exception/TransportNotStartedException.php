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
 * Thrown when a transport operation is invoked before `start()` has been called.
 */
final class TransportNotStartedException extends \LogicException implements McpExceptionInterface
{
    /**
     * @param non-empty-string $operation
     */
    public function __construct(string $operation)
    {
        parent::__construct(\sprintf('Cannot %s before start() has been called.', $operation));
    }
}
