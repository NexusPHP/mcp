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

namespace Nexus\Mcp\Core\Schema;

/**
 * Marker for a content block that can be embedded in a prompt message or tool
 * call result. Each variant carries its own `type` discriminator.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#contentblock
 */
interface ContentBlock
{
}
