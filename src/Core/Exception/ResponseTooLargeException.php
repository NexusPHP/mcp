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
 * Thrown when a peer's response exceeds the byte cap the reader is willing to hold in memory.
 */
final class ResponseTooLargeException extends \RuntimeException implements McpExceptionInterface
{
    public function __construct(int $maxBytes, ?\Throwable $previous = null)
    {
        parent::__construct(
            \sprintf('The response exceeded the %d byte limit the client accepts.', $maxBytes),
            previous: $previous,
        );
    }
}
