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

use Nexus\Assert\Assert;

/**
 * Outcome of scanning a tool `inputSchema`: the collected `x-mcp-header` bindings, or the first violated constraint.
 *
 * @internal
 */
final readonly class ParameterHeaderScanResult
{
    public bool $valid;

    /**
     * @param list<ParameterHeaderBinding> $bindings The declarations that hold, which an invalid result keeps only when its one fault is a property type
     * @param null|non-empty-string        $reason   The first violated constraint, null when every declaration holds
     */
    public function __construct(
        public array $bindings = [],
        public ?string $reason = null,
    ) {
        Assert::that($reason)->nullOr()->isNonEmptyString('A scan result\'s reason must name the violated constraint or be null.');

        $this->valid = null === $reason;
    }
}
