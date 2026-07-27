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

namespace Nexus\Mcp\Core\Auth;

/**
 * One `WWW-Authenticate` challenge: an authentication scheme and the `auth-param` pairs it carries.
 * Parameter names are matched and stored case-insensitively.
 *
 * @internal
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7235#section-4.1
 * @see https://datatracker.ietf.org/doc/html/rfc6750#section-3
 */
final readonly class WwwAuthenticateChallenge
{
    public const string BEARER_SCHEME = 'Bearer';

    /**
     * RFC 7230 `token`, the production both an auth-scheme and an auth-param name follow.
     */
    private const string TOKEN = '[!#$%&\'*+.^_`|~0-9A-Za-z-]+';

    /**
     * RFC 7230 `quoted-string`, capturing the delimited content.
     */
    private const string QUOTED_STRING = '"((?:[^"\\\\]|\\\\.)*)"';

    /**
     * RFC 7230 `quoted-string` with the closing delimiter optional, so an unterminated one still spans to
     * the end of the value rather than surrendering its commas to the segment split.
     */
    private const string QUOTED_SPAN = '"(?:[^"\\\\]|\\\\.)*"?';

    /**
     * @var array<non-empty-string, string>
     */
    public array $parameters;

    /**
     * @param non-empty-string                $scheme
     * @param array<non-empty-string, string> $parameters
     */
    public function __construct(public string $scheme, array $parameters = [])
    {
        $lowercased = [];

        foreach ($parameters as $name => $value) {
            $lowercased[strtolower($name)] = $value;
        }

        $this->parameters = $lowercased;
    }

    /**
     * Parses every challenge a `WWW-Authenticate` header value carries.
     *
     * @return list<self>
     */
    public static function parseAll(string $header): array
    {
        $challenges = [];
        $scheme = null;
        $parameters = [];

        foreach (self::splitSegments($header) as $segment) {
            if (preg_match('/^('.self::TOKEN.')[ \t]*(.*)$/s', trim($segment, " \t"), $matches) !== 1) {
                continue;
            }

            [, $name, $rest] = $matches;

            if (str_starts_with($rest, '=')) {
                if (null !== $scheme) {
                    $value = self::readValue(ltrim(substr($rest, 1), " \t"));

                    if (null !== $value) {
                        $parameters[$name] = $value;
                    }
                }

                continue;
            }

            if (null !== $scheme) {
                $challenges[] = new self($scheme, $parameters);
            }

            $scheme = $name;
            $parameters = self::readInlineParameter($rest);
        }

        if (null !== $scheme) {
            $challenges[] = new self($scheme, $parameters);
        }

        return $challenges;
    }

    /**
     * Finds the `Bearer` challenge a `WWW-Authenticate` header value carries, or `null` when it offers none.
     */
    public static function findBearer(string $header): ?self
    {
        foreach (self::parseAll($header) as $challenge) {
            if (strcasecmp($challenge->scheme, self::BEARER_SCHEME) === 0) {
                return $challenge;
            }
        }

        return null;
    }

    public function readParameter(string $name): ?string
    {
        return $this->parameters[strtolower($name)] ?? null;
    }

    /**
     * Renders the challenge as a `WWW-Authenticate` header value.
     */
    public function toHeaderValue(): string
    {
        $rendered = [];

        foreach ($this->parameters as $name => $value) {
            $rendered[] = \sprintf('%s="%s"', $name, addcslashes($value, '"\\'));
        }

        return [] === $rendered ? $this->scheme : \sprintf('%s %s', $this->scheme, implode(', ', $rendered));
    }

    /**
     * Splits a header value on the commas that separate challenges and parameters, leaving the commas
     * inside a quoted-string alone.
     *
     * @return list<string>
     */
    private static function splitSegments(string $header): array
    {
        preg_match_all('/'.self::QUOTED_SPAN.'|[^,"]+|,/s', $header, $matches);

        $segments = [];
        $current = '';

        foreach ($matches[0] as $piece) {
            if (',' === $piece) {
                $segments[] = $current;
                $current = '';

                continue;
            }

            $current .= $piece;
        }

        $segments[] = $current;

        return $segments;
    }

    /**
     * Reads the parameter sharing a segment with the scheme that introduces it, as in `Bearer realm="x"`.
     *
     * @return array<non-empty-string, string>
     */
    private static function readInlineParameter(string $rest): array
    {
        if (preg_match('/^('.self::TOKEN.')[ \t]*=[ \t]*(.*)$/s', $rest, $matches) !== 1) {
            return [];
        }

        $value = self::readValue($matches[2]);

        return null === $value ? [] : [$matches[1] => $value];
    }

    /**
     * Reads an `auth-param` value, either a quoted-string or a bare token.
     */
    private static function readValue(string $rest): ?string
    {
        if (! str_starts_with($rest, '"')) {
            return preg_match('/^'.self::TOKEN.'/', $rest, $matches) === 1 ? $matches[0] : null;
        }

        if (preg_match('/^'.self::QUOTED_STRING.'/s', $rest, $matches) !== 1) {
            // An unterminated quoted-string carries no value.
            return null;
        }

        return preg_replace('/\\\\(.)/s', '$1', $matches[1]);
    }
}
