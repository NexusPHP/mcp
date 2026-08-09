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
 * Codec for the Streamable HTTP `Mcp-Name` and `Mcp-Param-{Name}` header values.
 *
 * @internal
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#value-encoding
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
     * Decodes a header value, returning one without the sentinel unchanged and `null` when a wrapped payload
     * is not canonical Base64 or does not decode to valid UTF-8.
     */
    public static function decode(string $value): ?string
    {
        if (! self::hasSentinel($value)) {
            return $value;
        }

        $payload = substr($value, \strlen(self::SENTINEL_PREFIX), -\strlen(self::SENTINEL_SUFFIX));
        $decoded = base64_decode($payload, true);

        if (false === $decoded || base64_encode($decoded) !== $payload) {
            return null;
        }

        if (preg_match('//u', $decoded) !== 1) {
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

        return preg_match('/[^\x09\x20-\x7E]/', $value) === 1;
    }

    private static function hasSentinel(string $value): bool
    {
        return str_starts_with($value, self::SENTINEL_PREFIX) && str_ends_with($value, self::SENTINEL_SUFFIX);
    }
}
