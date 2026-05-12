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

namespace Nexus\Mcp\Core\Transport;

/**
 * Opaque handle returned by transport listener registrations.
 */
interface SubscriptionInterface
{
    /**
     * Removes the underlying listener. Idempotent: subsequent calls are no-ops.
     */
    public function dispose(): void;
}
