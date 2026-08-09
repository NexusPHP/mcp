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

namespace Nexus\Mcp\Core\Transport;

/**
 * Marks a transport that mirrors `x-mcp-header` tool parameters into request headers.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#custom-headers-from-tool-parameters
 */
interface ParameterHeaderMirroringInterface extends TransportInterface
{
}
