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

namespace Nexus\Mcp\Core\Validation;

use Nexus\Assert\Assert;

/**
 * Enforces the SEP-2133 extension identifier format: dot-separated vendor-prefix
 * labels, a slash, then a name, all drawn from the `_meta` key grammar with the
 * prefix mandatory.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/seps/2133-extensions
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic#meta
 */
final class ExtensionIdentifierValidator
{
    /**
     * @phpstan-assert non-empty-string $identifier
     *
     * @throws \InvalidArgumentException
     */
    public static function validate(string $identifier): void
    {
        Assert::that($identifier)->matchesRegularExpression(
            '/\A[A-Za-z](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*\/[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?\z/',
            'Extension identifier must be "{vendor-prefix}/{name}" following the "_meta" key grammar with a mandatory prefix, {value} given.',
        );
    }
}
