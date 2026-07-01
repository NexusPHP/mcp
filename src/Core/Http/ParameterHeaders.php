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

namespace Nexus\Mcp\Core\Http;

use Nexus\Mcp\Core\Schema\Error\HeaderMismatchError;

/**
 * Builds and validates the `Mcp-Param-{Name}` headers mirrored from tool-call arguments.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/draft/basic/transports/streamable-http#custom-headers-from-tool-parameters
 */
final class ParameterHeaders
{
    public const string HEADER_PREFIX = 'Mcp-Param-';

    /**
     * A strict decimal, gating the numeric comparison so the looser forms `Number()` would accept
     * (`0x1a`, ` 42 `, `1e3`) never coerce.
     */
    private const string DECIMAL_PATTERN = '/\A-?\d+(\.\d+)?\z/';

    /**
     * The largest integer JavaScript can represent exactly (`2 ** 53 - 1`).
     */
    private const int SAFE_INTEGER_MAX = 9_007_199_254_740_991;

    /**
     * Builds the client's outbound `Mcp-Param-{Name}` headers for one `tools/call`. A binding whose
     * argument is null, absent, or not a permitted primitive is omitted.
     *
     * @param list<ParameterHeaderBinding> $bindings
     * @param array<string, mixed>         $arguments
     *
     * @return array<string, string>
     */
    public static function build(array $bindings, array $arguments): array
    {
        $headers = [];

        foreach ($bindings as $binding) {
            $string = self::stringifyPrimitive(self::readValueAtPath($arguments, $binding->path));

            if (null === $string) {
                continue;
            }

            $headers[self::HEADER_PREFIX.$binding->headerName] = HeaderValueCodec::encode($string);
        }

        return $headers;
    }

    /**
     * Validates the `Mcp-Param-{Name}` headers a request carries against its body arguments. Returns the
     * mismatch to reject with, or null when every binding agrees.
     *
     * @param list<ParameterHeaderBinding> $bindings
     * @param array<string, mixed>         $arguments
     * @param array<string, string>        $headers   Header lines keyed by header name (matched case-insensitively)
     */
    public static function validate(array $bindings, array $arguments, array $headers): ?HeaderMismatchError
    {
        $headers = array_change_key_case($headers, \CASE_LOWER);

        foreach ($bindings as $binding) {
            $bodyValue = self::readValueAtPath($arguments, $binding->path);
            $bodyString = self::stringifyPrimitive($bodyValue);

            if (null === $bodyString) {
                // A null/absent argument (or a non-primitive that params validation owns) expects no header.
                continue;
            }

            $name = self::HEADER_PREFIX.$binding->headerName;
            $key = strtolower($name);

            if (! \array_key_exists($key, $headers)) {
                return new HeaderMismatchError(\sprintf('The %s header is absent but the request body carries its argument.', $name));
            }

            $decoded = HeaderValueCodec::decode($headers[$key]);

            if (null === $decoded) {
                return new HeaderMismatchError(\sprintf('The %s header is not a valid encoded value.', $name));
            }

            if (! self::matches($binding->type, $decoded, $bodyValue, $bodyString)) {
                return new HeaderMismatchError(\sprintf('The %s header does not match the request body argument.', $name));
            }
        }

        return null;
    }

    private static function matches(string $type, string $decoded, mixed $bodyValue, string $bodyString): bool
    {
        if ('integer' === $type && \is_int($bodyValue) && preg_match(self::DECIMAL_PATTERN, $decoded) === 1) {
            return (float) $decoded === (float) $bodyValue;
        }

        return $decoded === $bodyString;
    }

    private static function stringifyPrimitive(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value) && $value >= -self::SAFE_INTEGER_MAX && $value <= self::SAFE_INTEGER_MAX) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $path
     */
    private static function readValueAtPath(array $arguments, array $path): mixed
    {
        $node = $arguments;

        foreach ($path as $key) {
            if (! \is_array($node) || ! \array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }
}
