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
 * Per-tool task policy consumed by `TasksServerExtension`.
 */
final readonly class ToolTaskPolicy
{
    /**
     * @param bool $resolvesInputFirst Delegate synchronously until the call carries a continuation
     *                                 token, so input-required exchanges resolve before a task is created
     */
    public function __construct(public TaskSupport $support, public bool $resolvesInputFirst = false)
    {
    }
}
