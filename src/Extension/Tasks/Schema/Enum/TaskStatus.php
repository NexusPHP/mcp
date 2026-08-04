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

namespace Nexus\Mcp\Extension\Tasks\Schema\Enum;

/**
 * Lifecycle status of a long-running task.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2663-tasks-extension.md
 */
enum TaskStatus: string
{
    /**
     * The task's tool call is executing.
     */
    case Working = 'working';

    /**
     * The task awaits `tasks/update` answers to its pending input requests.
     */
    case InputRequired = 'input_required';

    /**
     * Terminal: the task finished with a stored result, tool errors included.
     */
    case Completed = 'completed';

    /**
     * Terminal: the task was cancelled cooperatively.
     */
    case Cancelled = 'cancelled';

    /**
     * Terminal: a protocol-level error ended the task.
     */
    case Failed = 'failed';
}
