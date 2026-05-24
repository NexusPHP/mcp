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

use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Severity-filtering STDERR logger for the examples.
 *
 * MCP servers MUST NOT write to STDOUT outside the JSON-RPC stream, so all
 * diagnostics go to STDERR. The threshold starts at `info`, or `debug` when the
 * `DEBUG` environment variable is set, and follows whatever a client later
 * requests through `logging/setLevel`.
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

    private string $minLevel;

    public function __construct()
    {
        $debug = in_array(strtolower((string) getenv('DEBUG')), ['1', 'true', 'on', 'yes'], true);
        $this->minLevel = $debug ? LogLevel::DEBUG : LogLevel::INFO;
    }

    public function setMinLevel(LoggingLevel $level): void
    {
        $this->minLevel = $level->value;
    }

    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = (string) $level;

        if (self::SEVERITY[$level] > self::SEVERITY[$this->minLevel]) {
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

        fwrite(\STDERR, sprintf("[%s] %s: %s\n", date(\DATE_RFC3339), $level, $rendered));
    }
}
