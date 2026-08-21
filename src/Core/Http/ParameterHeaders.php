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
 * Builder and validator for the `Mcp-Param-{Name}` headers mirrored from tool-call arguments.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#custom-headers-from-tool-parameters
 */
final class ParameterHeaders
{
    public const string HEADER_PREFIX = 'Mcp-Param-';

    /**
     * A strict decimal, excluding the looser forms `Number()` would accept (`0x1a`, ` 42 `, `1e3`).
     */
    private const string DECIMAL_PATTERN = '/\A-?\d+(\.\d+)?\z/';

    /**
     * The largest integer JavaScript can represent exactly (`2 ** 53 - 1`).
     */
    private const int SAFE_INTEGER_MAX = 9_007_199_254_740_991;

    /**
     * Builds the client's outbound `Mcp-Param-{Name}` headers for one `tools/call`, omitting a binding whose
     * argument is null, absent, or not a permitted primitive.
     *
     * @param list<ParameterHeaderBinding> $bindings
     * @param array<array-key, mixed>      $arguments
     *
     * @return array<non-empty-string, string>
     */
    public static function build(array $bindings, array $arguments): array
    {
        $headers = [];

        foreach ($bindings as $binding) {
            $value = self::normaliseIntegralFloat(self::readValueAtPath($arguments, $binding->path));
            $string = self::stringifyPrimitive($value);

            if (null === $string || self::exceedsSafeInteger($value)) {
                continue;
            }

            $headers[self::HEADER_PREFIX.$binding->headerName] = HeaderValueCodec::encode($string);
        }

        return $headers;
    }

    /**
     * Validates the `Mcp-Param-{Name}` headers a request carries against its body arguments, returning the
     * mismatch to reject with or null when every binding agrees.
     *
     * @param list<ParameterHeaderBinding> $bindings
     * @param array<array-key, mixed>      $arguments
     * @param array<string, string>        $headers
     */
    public static function validate(array $bindings, array $arguments, array $headers): ?HeaderMismatchError
    {
        $headers = array_change_key_case($headers, \CASE_LOWER);

        foreach ($bindings as $binding) {
            $name = self::HEADER_PREFIX.$binding->headerName;
            $key = strtolower($name);
            $bodyValue = self::normaliseIntegralFloat(self::readValueAtPath($arguments, $binding->path));
            $bodyString = self::stringifyPrimitive($bodyValue);

            if (! \array_key_exists($key, $headers)) {
                if (null === $bodyString || self::exceedsSafeInteger($bodyValue)) {
                    continue;
                }

                return new HeaderMismatchError(\sprintf('The %s header is absent but the request body carries its argument.', $name));
            }

            if (null === $bodyString) {
                return new HeaderMismatchError(\sprintf('The %s header is present but the request body carries no argument it can mirror.', $name));
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
        if ('integer' === $type && \is_int($bodyValue) && ! self::exceedsSafeInteger($bodyValue) && preg_match(self::DECIMAL_PATTERN, $decoded) === 1) {
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

        if (\is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * The integer that JSON Schema and a JavaScript peer both read an integral float such as `5.0` as.
     */
    private static function normaliseIntegralFloat(mixed $value): mixed
    {
        if (\is_float($value) && is_finite($value) && abs($value) <= self::SAFE_INTEGER_MAX && (float) (int) $value === $value) {
            return (int) $value;
        }

        return $value;
    }

    private static function exceedsSafeInteger(mixed $value): bool
    {
        return \is_int($value) && ($value > self::SAFE_INTEGER_MAX || $value < -self::SAFE_INTEGER_MAX);
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param list<string>            $path
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
