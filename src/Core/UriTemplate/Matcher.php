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

/**
 * Reverse-matches a concrete URI against an RFC 6570 Level 1 URI template,
 * producing variable bindings on success or null on no-match. Captured values
 * are `rawurldecode`d, so callers treating a binding as a path component must
 * sanitise the decoded result themselves.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6570#section-3.2.2
 */
final class Matcher
{
    /**
     * @param non-empty-string $template A template already passed through `Validator`
     *
     * @return null|array<string, string>
     */
    public static function match(string $template, string $uri): ?array
    {
        preg_match_all('/\{[A-Za-z_][A-Za-z0-9_]*\}/', $template, $matches, \PREG_OFFSET_CAPTURE);

        $pattern = '\A';
        $cursor = 0;
        $seen = [];

        foreach ($matches[0] as [$expr, $exprStart]) {
            $name = substr($expr, 1, -1);

            $pattern .= preg_quote(substr($template, $cursor, $exprStart - $cursor), '~');

            if (\in_array($name, $seen, true)) {
                // Repeated name: backreference so it must capture the same text.
                $pattern .= \sprintf('(?P=%s)', $name);
            } else {
                $pattern .= \sprintf('(?P<%s>[^/?#]+)', $name);
                $seen[] = $name;
            }

            $cursor = $exprStart + \strlen($expr);
        }

        $pattern .= preg_quote(substr($template, $cursor), '~').'\z';

        if (preg_match(\sprintf('~%s~', $pattern), $uri, $captures) !== 1) {
            return null;
        }

        $bindings = [];

        foreach ($captures as $key => $value) {
            if (\is_string($key)) {
                $bindings[$key] = rawurldecode($value);
            }
        }

        return $bindings;
    }
}
