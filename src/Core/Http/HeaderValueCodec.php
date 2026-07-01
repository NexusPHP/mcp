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

/**
 * Codec for a Streamable HTTP header value, wrapping non-header-safe values in the `=?base64?...?=`
 * sentinel. Carries the `Mcp-Name` and `Mcp-Param-{Name}` values.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/draft/basic/transports/streamable-http#value-encoding
 */
final class HeaderValueCodec
{
    private const string SENTINEL_PREFIX = '=?base64?';
    private const string SENTINEL_SUFFIX = '?=';

    public static function encode(string $value): string
    {
        if (! self::needsBase64($value)) {
            return $value;
        }

        return self::SENTINEL_PREFIX.base64_encode($value).self::SENTINEL_SUFFIX;
    }

    /**
     * Decodes a header value, returning the decoded string, or `null` when a sentinel-wrapped payload is
     * not canonical Base64 or does not decode to valid UTF-8. A value without the sentinel is returned
     * unchanged.
     */
    public static function decode(string $value): ?string
    {
        if (! self::hasSentinel($value)) {
            return $value;
        }

        $payload = substr($value, \strlen(self::SENTINEL_PREFIX), -\strlen(self::SENTINEL_SUFFIX));
        $decoded = base64_decode($payload, true);

        if (false === $decoded || base64_encode($decoded) !== $payload) {
            // Reject a non-canonical payload (invalid alphabet or wrong padding).
            return null;
        }

        if (preg_match('//u', $decoded) !== 1) {
            // Reject a payload that does not decode to valid UTF-8.
            return null;
        }

        return $decoded;
    }

    private static function needsBase64(string $value): bool
    {
        if ('' === $value) {
            return true;
        }

        if (self::hasSentinel($value)) {
            return true;
        }

        if (trim($value) !== $value) {
            return true;
        }

        // Any byte outside horizontal tab and the visible ASCII range forces Base64.
        return preg_match('/[^\x09\x20-\x7E]/', $value) === 1;
    }

    private static function hasSentinel(string $value): bool
    {
        return str_starts_with($value, self::SENTINEL_PREFIX) && str_ends_with($value, self::SENTINEL_SUFFIX);
    }
}
