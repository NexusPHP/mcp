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

namespace Nexus\Mcp\Core\Schema\Enum;

/**
 * The status of a task.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#taskstatus
 */
enum TaskStatus: string
{
    case Working = 'working';
    case InputRequired = 'input_required';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
