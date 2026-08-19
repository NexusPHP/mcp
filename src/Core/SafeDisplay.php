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

namespace Nexus\Mcp\Core;

/**
 * Renderer for the peer-derived strings appearing in log lines, exception messages, and JSON-RPC error payloads.
 *
 * Never un-escape the result, since a backslash is not itself escaped.
 */
final class SafeDisplay
{
    private const int MAX_LENGTH = 80;
    private const int MAX_CAUSE_LENGTH = 256;

    /**
     * Escapes every byte outside printable ASCII as `\xNN` and caps a short identifier at `MAX_LENGTH`.
     */
    public static function sanitise(string $value): string
    {
        return self::render($value, self::MAX_LENGTH);
    }

    /**
     * The same at `MAX_CAUSE_LENGTH`, for a composed message or a URI.
     */
    public static function sanitiseCause(string $message): string
    {
        return self::render($message, self::MAX_CAUSE_LENGTH);
    }

    /**
     * The same for a string request id, passing an int one through unchanged.
     */
    public static function sanitiseId(int|string $id): int|string
    {
        return \is_string($id) ? self::render($id, self::MAX_LENGTH) : $id;
    }

    private static function render(string $value, int $maxLength): string
    {
        $escaped = preg_replace_callback(
            '/[^\x20-\x7E]/',
            static fn(array $matches): string => \sprintf('\\x%02x', \ord($matches[0])),
            $value,
        ) ?? '';

        if ($maxLength < \strlen($escaped)) {
            return substr($escaped, 0, $maxLength - 3).'...';
        }

        return $escaped;
    }
}
