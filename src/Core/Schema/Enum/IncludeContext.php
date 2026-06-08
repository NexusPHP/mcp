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
 * Controls whether and how MCP servers should be consulted for context to be
 * attached to a sampling prompt.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/draft/schema.ts
 */
enum IncludeContext: string
{
    /**
     * Include context from every connected MCP server.
     */
    case AllServers = 'allServers';

    /**
     * Send no server-supplied context with the prompt.
     */
    case None = 'none';

    /**
     * Include context only from the requesting server.
     */
    case ThisServer = 'thisServer';
}
