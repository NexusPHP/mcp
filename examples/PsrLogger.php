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

final class PsrLogger extends AbstractLogger
{
    private const array RFC5424_SEVERITY = [
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
        $this->threshold = $debug ? self::RFC5424_SEVERITY[LogLevel::DEBUG] : self::RFC5424_SEVERITY[LogLevel::INFO];
    }

    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $name = is_string($level) ? $level : LogLevel::ERROR;
        $severity = self::RFC5424_SEVERITY[$name] ?? self::RFC5424_SEVERITY[LogLevel::ERROR];

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
                $placeholder = sprintf('{%s}', $key);

                if (is_scalar($normalized) && preg_match('/^[A-Za-z0-9_.]+$/', (string) $key) === 1 && str_contains($rendered, $placeholder)) {
                    $replacements[$placeholder] = (string) $normalized;

                    continue;
                }

                $encodable[$key] = $normalized;
            }

            $rendered = strtr($rendered, $replacements);

            if ([] !== $encodable) {
                $rendered = sprintf(
                    '%s %s',
                    $rendered,
                    json_encode($encodable, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
                );
            }
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
