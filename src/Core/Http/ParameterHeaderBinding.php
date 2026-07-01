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

namespace Nexus\Mcp\Core\Http;

/**
 * A binding of a tool parameter to a `Mcp-Param-{Name}` header, declared by an `x-mcp-header`
 * annotation in a tool `inputSchema`.
 *
 * @internal
 */
final readonly class ParameterHeaderBinding
{
    /**
     * @param list<string>     $path       Property path from the arguments root to the declaring property
     * @param non-empty-string $headerName The `{Name}` in `Mcp-Param-{Name}`, case preserved as declared
     * @param non-empty-string $type       The declaring property's JSON Schema `type`
     */
    public function __construct(
        public array $path,
        public string $headerName,
        public string $type,
    ) {
    }
}
