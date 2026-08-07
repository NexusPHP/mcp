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
 * Enforces the format the spec recommends for tool names, applied to every handle the SDK authors:
 * tools, prompts, resources and resource templates. The rule is SHOULD and the schema carries no
 * `pattern`, so it never runs on a decode path, which takes whatever a peer sends.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/server/tools#tool-names
 */
final class IdentifierNameValidator
{
    /**
     * @param non-empty-string $context Label prefix for the error message (e.g. "Resource", "Tool")
     *
     * @phpstan-assert non-empty-string $name
     *
     * @throws ExpectationFailedException
     */
    public static function validate(string $name, string $context): void
    {
        Assert::that($name)->matchesRegularExpression(
            '/\A[A-Za-z0-9_.-]{1,128}\z/',
            \sprintf('%s must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", {value} given.', $context),
        );
    }
}
