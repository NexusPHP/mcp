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
 * Per-tool task support mode declared by `ToolExecution.taskSupport`.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
enum TaskSupport: string
{
    /**
     * Tool does not support task-augmented execution.
     */
    case Forbidden = 'forbidden';

    /**
     * Tool may support task-augmented execution.
     */
    case Optional = 'optional';

    /**
     * Tool requires task-augmented execution.
     */
    case Required = 'required';
}
