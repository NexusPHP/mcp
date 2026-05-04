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
 * Enforces the RFC 3986 absolute-URI shape on a string: ASCII-printable only
 * (no whitespace or control characters) and a scheme + optional authority +
 * path/query/fragment structure. Used by spec types whose `uri` field is a
 * generic resource identifier with no scheme restriction (e.g. `Resource`,
 * `ResourceContents`). Stricter checks like `Root.uri`'s `file://` prefix or
 * `Implementation.websiteUrl`'s HTTP/HTTPS-only regex stay at the call site.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986
 */
final class Rfc3986UriValidator
{
    /**
     * @param non-empty-string $context label prefix for the error message (e.g. "Resource", "ResourceContents")
     *
     * @throws ExpectationFailedException
     *
     * @phpstan-assert non-empty-string $uri
     */
    public static function validate(string $uri, string $context): void
    {
        Assert::that($uri)
            ->isNonEmptyString(\sprintf('%s URI must be a non-empty string.', $context))
            ->matchesRegularExpression(
                '/\A[\x21-\x7E]+\z/',
                \sprintf('%s URI must contain only ASCII printable characters (no whitespace or control characters), got {value}.', $context),
            )
            ->matchesRegularExpression(
                '/\A[A-Za-z][A-Za-z0-9+.\-]*:(?:\/\/[^\/?#]*)?[^?#]*(?:\?[^#]*)?(?:#.*)?\z/',
                \sprintf('%s URI must be a valid RFC 3986 absolute URI, got {value}.', $context),
            )
        ;
    }
}
