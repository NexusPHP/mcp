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
 * Severity-filtering STDERR logger for the examples, printing the interpolated
 * PSR-3 message followed by the JSON-encoded context on its own line.
 *
 * MCP servers MUST NOT write to STDOUT outside the JSON-RPC stream, so all
 * diagnostics go to STDERR. The threshold starts at `info`, or `debug` when the
 * `DEBUG` environment variable is set.
 */
final class PsrLogger extends AbstractLogger
{
    /**
     * RFC 5424 severity index (0 = most severe, 7 = least), keyed by PSR-3 level name.
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

    private int $threshold;

    public function __construct()
    {
        $debug = in_array(strtolower((string) getenv('DEBUG')), ['1', 'true', 'on', 'yes'], true);
        $this->threshold = $debug ? self::SEVERITY[LogLevel::DEBUG] : self::SEVERITY[LogLevel::INFO];
    }

    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $name = is_string($level) ? $level : LogLevel::ERROR;
        $severity = self::SEVERITY[$name] ?? self::SEVERITY[LogLevel::ERROR];

        if ($severity > $this->threshold) {
            return;
        }

        $rendered = (string) $message;

        if ([] !== $context) {
            $replacements = [];
            $encodable = [];

            foreach ($context as $key => $value) {
                $normalized = match (true) {
                    'exception' === $key && $value instanceof Throwable => self::describeThrowable($value),
                    $value instanceof Stringable => (string) $value,
                    default => $value,
                };
                $encodable[$key] = $normalized;

                if (is_scalar($normalized) && preg_match('/^[A-Za-z0-9_.]+$/', (string) $key) === 1) {
                    $replacements[sprintf('{%s}', $key)] = (string) $normalized;
                }
            }

            $rendered = sprintf(
                '%s %s',
                strtr($rendered, $replacements),
                json_encode($encodable, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            );
        }

        fwrite(\STDERR, sprintf("[%s] %s: %s\n", date(\DATE_RFC3339), $name, $rendered));
    }

    private static function describeThrowable(Throwable $exception): string
    {
        return sprintf(
            '[%s] %s',
            $exception::class,
            $exception->getMessage(),
        );
    }
}
