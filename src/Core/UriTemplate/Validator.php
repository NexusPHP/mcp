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

namespace Nexus\Mcp\Core\UriTemplate;

use Nexus\Assert\Assert;
use Nexus\Assert\ExpectationFailedException;

/**
 * Enforces the subset of RFC 6570 that `Matcher` can reverse-match: Level 1 `{name}` expressions over
 * `[A-Za-z_][A-Za-z0-9_]*`, separated by at least one literal.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6570#section-3.2.2
 */
final class Validator
{
    /**
     * @param non-empty-string $context Label prefix for the error message (e.g. "ResourceTemplate")
     *
     * @throws ExpectationFailedException
     */
    public static function validate(string $template, string $context): void
    {
        Assert::that(substr_count($template, '{'))
            ->isIdentical(preg_match_all('/\{[A-Za-z_][A-Za-z0-9_]*\}/', $template), \sprintf(
                '%s URI template must use only RFC 6570 Level 1 simple-name expressions (e.g. `{name}`), got "%s".',
                $context,
                $template,
            ))
        ;
        Assert::that($template)
            ->not()
            ->contains('}{', \sprintf(
                '%s URI template must include literal text between adjacent expressions, got "%s".',
                $context,
                $template,
            ))
        ;
    }
}
