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

namespace Nexus\Mcp\Core\Dispatch;

/**
 * A dispatcher that can cancel every request in flight at once.
 */
interface CancellableMessageDispatcherInterface extends MessageDispatcherInterface
{
    public function cancelAll(): void;
}
