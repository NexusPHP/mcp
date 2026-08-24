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

    private const string SEGMENT_PATTERN = '/^('.self::TOKEN.')[ \t]*(.*)$/s';
    private const string INLINE_PARAMETER_PATTERN = '/^('.self::TOKEN.')[ \t]*=[ \t]*(.*)$/s';
    private const string TOKEN_VALUE_PATTERN = '/^'.self::TOKEN.'/';

    /**
     * A leading RFC 7230 `quoted-string`, capturing the delimited content.
     */
    private const string QUOTED_VALUE_PATTERN = '/^"((?:[^"\\\\]|\\\\.)*)"/s';

    /**
     * Quoted spans (closing delimiter optional), runs outside quotes, and separator commas.
     */
    private const string SEGMENT_PIECES_PATTERN = '/"(?:[^"\\\\]|\\\\.)*"?|[^,"]+|,/s';

    /**
     * The control octets RFC 9110 excludes from a field value, HTAB aside, stripped in both directions.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9110#section-5.5
     */
    private const string FORBIDDEN_OCTETS = '/[\x00-\x08\x0A-\x1F\x7F]/';

    /**
     * @var array<non-empty-string, string>
     */
    public array $parameters;

    /**
     * @param non-empty-string                $scheme
     * @param array<non-empty-string, string> $parameters
     */
    public function __construct(
        public string $scheme,
        array $parameters = [],
    ) {
        $lowercased = [];

        foreach ($parameters as $name => $value) {
            $lowercased[strtolower($name)] = $value;
        }

        $this->parameters = $lowercased;
    }

    /**
     * @return list<self>
     */
    public static function parseAll(string $header): array
    {
        $challenges = [];
        $scheme = null;
        $parameters = [];

        foreach (self::splitSegments($header) as $segment) {
            if (preg_match(self::SEGMENT_PATTERN, trim($segment, " \t"), $matches) !== 1) {
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
     * Renders the challenge as a field value carrying no control octet, so no part of it can inject a
     * header of its own.
     */
    public function toHeaderValue(): string
    {
        $rendered = [];

        foreach ($this->parameters as $name => $value) {
            $rendered[] = \sprintf('%s="%s"', $name, addcslashes($value, '"\\'));
        }

        $header = [] === $rendered ? $this->scheme : \sprintf('%s %s', $this->scheme, implode(', ', $rendered));

        return (string) preg_replace(self::FORBIDDEN_OCTETS, '', $header);
    }

    /**
     * Splits a header value on the commas that separate challenges and parameters, leaving the commas
     * inside a quoted-string alone.
     *
     * @return list<string>
     */
    private static function splitSegments(string $header): array
    {
        preg_match_all(self::SEGMENT_PIECES_PATTERN, $header, $matches);

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
        if (preg_match(self::INLINE_PARAMETER_PATTERN, $rest, $matches) !== 1) {
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
            return preg_match(self::TOKEN_VALUE_PATTERN, $rest, $matches) === 1 ? $matches[0] : null;
        }

        if (preg_match(self::QUOTED_VALUE_PATTERN, $rest, $matches) !== 1) {
            return null;
        }

        $unescaped = (string) preg_replace('/\\\\(.)/s', '$1', $matches[1]);

        return preg_replace(self::FORBIDDEN_OCTETS, '', $unescaped);
    }
}
