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

/**
 * Enforces the subset of RFC 6570 that `Matcher` can reverse-match: Level 1 `{name}` expressions over
 * `[A-Za-z_][A-Za-z0-9_]*` of at most 32 characters, separated by at least one literal.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6570#section-3.2.2
 */
final class Validator
{
    /**
     * PCRE2's group-name limit before 10.44, which `Matcher::compile` names its capture groups with.
     */
    private const int MAX_VARIABLE_NAME_LENGTH = 32;

    /**
     * @param non-empty-string $context Label prefix for the error message (e.g. "ResourceTemplate")
     *
     * @throws \InvalidArgumentException
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
        Assert::that($template)
            ->not()
            ->matchesRegularExpression(\sprintf('/\{[A-Za-z_][A-Za-z0-9_]{%d,}\}/', self::MAX_VARIABLE_NAME_LENGTH), \sprintf(
                '%s URI template variable names must be at most %d characters, got "%s".',
                $context,
                self::MAX_VARIABLE_NAME_LENGTH,
                $template,
            ))
        ;
    }
}
