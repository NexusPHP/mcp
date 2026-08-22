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
 * Enforces the RFC 3986 absolute-URI shape on a string: ASCII-printable only, with a scheme plus optional
 * authority and path/query/fragment.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986
 */
final class Rfc3986UriValidator
{
    /**
     * @param non-empty-string $context Label prefix for the error message (e.g. "Resource", "ResourceContents")
     *
     * @throws \InvalidArgumentException
     *
     * @phpstan-assert non-empty-string $uri
     */
    public static function validate(string $uri, string $context): void
    {
        Assert::that($uri)
            ->isNonEmptyString(\sprintf('%s must be a non-empty string.', $context))
            ->matchesRegularExpression(
                '/\A[\x21-\x7E]+\z/',
                \sprintf('%s must contain only ASCII printable characters (no whitespace or control characters), {value} given.', $context),
            )
            ->matchesRegularExpression(
                '/\A[A-Za-z][A-Za-z0-9+.\-]*:(?:\/\/[^\/?#]*)?[^?#]*(?:\?[^#]*)?(?:#.*)?\z/',
                \sprintf('%s must be a valid RFC 3986 absolute URI, {value} given.', $context),
            )
        ;
    }
}
