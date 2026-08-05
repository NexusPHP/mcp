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

namespace Nexus\Mcp\Extension\Apps\Schema\Enum;

/**
 * Who can access a UI-linked tool.
 *
 * @see https://github.com/modelcontextprotocol/ext-apps/blob/main/specification/2026-01-26/apps.mdx
 */
enum ToolVisibility: string
{
    /**
     * The tool is visible to and callable by the model.
     */
    case Model = 'model';

    /**
     * The tool is callable by the embedded app from the same server.
     */
    case App = 'app';
}
