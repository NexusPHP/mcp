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
 * Thrown when a supervised transport has spent its restart budget and stops respawning the peer.
 */
final class SupervisionExhaustedException extends \RuntimeException implements McpExceptionInterface
{
    public function __construct(
        public readonly int $restarts,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Gave up supervising the peer after %d restart attempt(s) in one window.', $restarts),
            previous: $previous,
        );
    }
}
