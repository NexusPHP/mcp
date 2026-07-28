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
 * The obligations the spec places on a client only bind on such a transport: it must exclude a tool whose
 * declarations violate the scanner constraints from its `tools/list` result, and it must mirror the annotated
 * arguments of a `tools/call` into `Mcp-Param-{Name}` headers. A transport without the marker (stdio) may
 * ignore the annotations entirely, so the client leaves its tool listing untouched.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#custom-headers-from-tool-parameters
 */
interface ParameterHeaderMirroringInterface extends TransportInterface
{
}
