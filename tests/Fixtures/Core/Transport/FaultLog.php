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

namespace Nexus\Mcp\Tests\Fixtures\Core\Transport;

/**
 * Ordered record of the faults a transport reported to its error listeners.
 *
 * @internal
 */
final class FaultLog
{
    /**
     * @var list<string>
     */
    public private(set) array $messages = [];

    public function record(\Throwable $fault): void
    {
        $this->messages[] = $fault->getMessage();
    }
}
