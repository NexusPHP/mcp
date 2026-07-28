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

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Severity-filtering STDERR logger for the examples.
 *
 * MCP servers MUST NOT write to STDOUT outside the JSON-RPC stream, so all
 * diagnostics go to STDERR. The threshold starts at `info`, or `debug` when the
 * `DEBUG` environment variable is set.
 */
final class ExampleLogger extends AbstractLogger
{
    /**
     * RFC 5424 severity index (0 = most severe, 7 = least), keyed by PSR-3
     * level name.
     */
    private const array SEVERITY = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];

    /**
     * Severity index at or below which a record is written.
     */
    private int $threshold;

    public function __construct()
    {
        $debug = in_array(strtolower((string) getenv('DEBUG')), ['1', 'true', 'on', 'yes'], true);
        $this->threshold = $debug ? self::SEVERITY[LogLevel::DEBUG] : self::SEVERITY[LogLevel::INFO];
    }

    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        // PSR-3 types the level as `mixed`, so an unrecognised one is possible and
        // is written rather than dropped: losing a diagnostic is worse than an odd label.
        $name = is_string($level) ? $level : '(unknown level)';
        $severity = self::SEVERITY[$name] ?? self::SEVERITY[LogLevel::ERROR];

        if ($severity > $this->threshold) {
            return;
        }

        $replacements = [];

        foreach ($context as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $replacements[sprintf('{%s}', $key)] = match (true) {
                $value instanceof Throwable => $value::class.': '.$value->getMessage(),
                is_scalar($value) || $value instanceof Stringable => (string) $value,
                default => json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            };
        }

        $rendered = strtr((string) $message, $replacements);

        fwrite(\STDERR, sprintf("[%s] %s: %s\n", date(\DATE_RFC3339), $name, $rendered));
    }
}
