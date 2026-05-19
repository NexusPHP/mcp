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

namespace Nexus\Mcp\Core\JsonRpc;

/**
 * Renders a peer-derived string into a form safe for formatting into log
 * lines, exception messages, and JSON-RPC error payloads. Non-printable bytes
 * are escaped as `\xNN`. The result is capped at 80 bytes (ellipsis appended).
 *
 * @internal
 */
final class SafeDisplay
{
    public const int MAX_LENGTH = 80;

    public static function sanitise(string $value): string
    {
        $escaped = preg_replace_callback(
            '/[^\x20-\x7E]/',
            static fn(array $matches): string => \sprintf('\\x%02x', \ord($matches[0])),
            $value,
        ) ?? '';

        if (\strlen($escaped) > self::MAX_LENGTH) {
            return substr($escaped, 0, self::MAX_LENGTH - 3).'...';
        }

        return $escaped;
    }
}
