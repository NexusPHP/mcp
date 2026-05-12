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
 * Thrown when `start()` is called on a transport that is already running.
 */
final class TransportAlreadyStartedException extends \LogicException implements McpExceptionInterface
{
    /**
     * @param class-string $transport
     */
    public function __construct(string $transport)
    {
        parent::__construct(\sprintf('%s has already been started.', $transport));
    }
}
