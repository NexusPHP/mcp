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

namespace Nexus\Mcp\Extension\Tasks;

/**
 * Protocol vocabulary of the tasks extension (`io.modelcontextprotocol/tasks`, SEP-2663).
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2663-tasks-extension.md
 */
final readonly class Tasks
{
    public const string IDENTIFIER = 'io.modelcontextprotocol/tasks';
}
