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
     * Compiles a template into a ready-to-use PCRE pattern. Callers in hot paths
     * should compile once and pass the result to `matchCompiled()` per request.
     *
     * @param non-empty-string $template A template already passed through `Validator`
     *
     * @return non-empty-string
     */
    public static function compile(string $template): string
    {
        preg_match_all('/\{[A-Za-z_][A-Za-z0-9_]*\}/', $template, $matches, \PREG_OFFSET_CAPTURE);

        $body = '\A';
        $cursor = 0;
        $seen = [];

        foreach ($matches[0] as [$expr, $exprStart]) {
            $name = substr($expr, 1, -1);

            $body .= preg_quote(substr($template, $cursor, $exprStart - $cursor), '~');

            if (\in_array($name, $seen, true)) {
                // Repeated name: backreference so it must capture the same text.
                $body .= \sprintf('(?P=%s)', $name);
            } else {
                $body .= \sprintf('(?P<%s>[^/?#]+)', $name);
                $seen[] = $name;
            }

            $cursor = $exprStart + \strlen($expr);
        }

        $body .= preg_quote(substr($template, $cursor), '~').'\z';

        return \sprintf('~%s~', $body);
    }

    /**
     * @param non-empty-string $pattern A pattern returned by `compile()`
     *
     * @return null|array<string, string>
     */
    public static function matchCompiled(string $pattern, string $uri): ?array
    {
        if (preg_match($pattern, $uri, $captures) !== 1) {
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

    /**
     * One-shot convenience: compile the template and match in a single call.
     * Prefer `compile()` + `matchCompiled()` when the same template is matched
     * repeatedly.
     *
     * @param non-empty-string $template A template already passed through `Validator`
     *
     * @return null|array<string, string>
     */
    public static function match(string $template, string $uri): ?array
    {
        return self::matchCompiled(self::compile($template), $uri);
    }
}
