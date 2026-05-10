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
 * Enforces a stable identifier-name format: 1-128 characters drawn from
 * `A-Z`, `a-z`, `0-9`, `_`, `-`, and `.`. Applied to spec types whose name
 * is a stable handle that clients invoke (tools, prompts, resources,
 * resource templates), not to free-form display names.
 *
 * The 2025-11-25 spec normatively specifies this format only for tool names
 * (per SEP-986); the SDK extends the same constraint to other named
 * identifiers as a uniform convention.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/server/tools#tool-names
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/issues/986
 */
final class IdentifierNameValidator
{
    /**
     * @param non-empty-string $context label prefix for the error message (e.g. "Resource", "Tool")
     *
     * @throws ExpectationFailedException when `$name` does not match the identifier pattern
     *
     * @phpstan-assert non-empty-string $name
     */
    public static function validate(string $name, string $context): void
    {
        Assert::that($name)->matchesRegularExpression(
            '/\A[A-Za-z0-9_.-]{1,128}\z/',
            \sprintf('%s name must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", got {value}.', $context),
        );
    }
}
