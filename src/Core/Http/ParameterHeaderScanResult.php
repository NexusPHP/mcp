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
 * Outcome of scanning a tool `inputSchema`: the collected `x-mcp-header` bindings, or the first violated constraint.
 *
 * @internal
 */
final readonly class ParameterHeaderScanResult
{
    /**
     * @param list<ParameterHeaderBinding> $bindings
     */
    private function __construct(
        public bool $valid,
        public array $bindings,
        public ?string $reason,
    ) {
    }

    /**
     * @param list<ParameterHeaderBinding> $bindings
     */
    public static function valid(array $bindings): self
    {
        return new self(true, $bindings, null);
    }

    public static function invalid(string $reason): self
    {
        return new self(false, [], $reason);
    }
}
