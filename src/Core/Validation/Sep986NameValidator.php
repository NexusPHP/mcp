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
use Nexus\Assert\ExpectationFailedException;

/**
 * Enforces the SEP-986 identifier-name format: 1-64 characters drawn from
 * `A-Z`, `a-z`, `0-9`, `_`, `-`, `.`, and `/`. Used by spec types whose name
 * is a stable handle that clients invoke (tools, prompts, resources,
 * resource templates), not by free-form display names.
 *
 * @internal
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/issues/986
 */
final class Sep986NameValidator
{
    /**
     * @param non-empty-string $context label prefix for the error message (e.g. "Resource", "Tool")
     *
     * @throws ExpectationFailedException when `$name` does not match the SEP-986 pattern
     *
     * @phpstan-assert non-empty-string $name
     */
    public static function validate(string $name, string $context): void
    {
        Assert::that($name)->matchesRegularExpression(
            '/\A[A-Za-z0-9_.\/-]{1,64}\z/',
            \sprintf('%s name must be 1-64 characters of A-Z, a-z, 0-9, ".", "/", "-", or "_" (SEP-986), got {value}.', $context),
        );
    }
}
