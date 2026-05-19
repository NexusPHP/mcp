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
 * Enforces the RFC 6570 URI Template shape on a string: ASCII-printable only
 * (no whitespace or control characters), a scheme prefix per RFC 3986, and a
 * body composed of literals and well-formed `{expression}` segments. Used by
 * spec types whose `uri` / `uriTemplate` field carries the `format: uri-template`
 * hint (e.g. `ResourceTemplate`, `ResourceTemplateReference`).
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6570
 */
final class Rfc6570UriTemplateValidator
{
    /**
     * @param non-empty-string $context Label prefix for the error message (e.g. "ResourceTemplate", "ResourceTemplateReference")
     *
     * @throws ExpectationFailedException
     *
     * @phpstan-assert non-empty-string $uriTemplate
     */
    public static function validate(string $uriTemplate, string $context): void
    {
        Assert::that($uriTemplate)
            ->isNonEmptyString(\sprintf('%s must be a non-empty string.', $context))
            ->matchesRegularExpression(
                '/\A[\x21-\x7E]+\z/',
                \sprintf('%s must contain only ASCII printable characters (no whitespace or control characters), got {value}.', $context),
            )
            ->matchesRegularExpression(
                '/\A[A-Za-z][A-Za-z0-9+.\-]*:[^{}]*(?:\{[^{}]+\}[^{}]*)*\z/',
                \sprintf('%s must be a valid RFC 6570 URI template, got {value}.', $context),
            )
        ;
    }
}
