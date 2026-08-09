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

namespace Nexus\Mcp\Extension\Tasks\Server;

/**
 * How a tool participates in task execution.
 */
enum TaskSupport
{
    /**
     * Runs as a task for a declaring client and synchronously otherwise.
     */
    case Optional;

    /**
     * Runs as a task for a declaring client and refuses a non-declaring
     * client outright.
     */
    case Required;
}
