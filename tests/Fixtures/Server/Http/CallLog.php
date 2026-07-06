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

namespace Nexus\Mcp\Tests\Fixtures\Server\Http;

/**
 * Ordered record of labels, shared across middleware doubles to observe pipeline execution order.
 *
 * @internal
 */
final class CallLog
{
    /**
     * @var list<string>
     */
    public private(set) array $labels = [];

    public function record(string $label): void
    {
        $this->labels[] = $label;
    }
}
