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

namespace Nexus\Mcp\Core\Validation;

/**
 * Parses an ISO 8601 / RFC 3339 datetime string into `\DateTimeImmutable`.
 *
 * @internal
 */
final class Iso8601DateTimeValidator
{
    private const string RFC3339_PATTERN = <<<'EOD'
        /\A
            (?P<datetime>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})
            (?:\.(?P<secfrac>\d+))?
            (?P<offset>[Zz]|[+-](?:[01]\d|2[0-3]):[0-5]\d)
        \z/x
        EOD;
    private const string MICROSECOND_FORMAT = 'Y-m-d\TH:i:s.uP';
    private const int MICROSECOND_DIGITS = 6;

    /**
     * @param non-empty-string $context Label prefix for the error message (e.g. "Task createdAt")
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $value, string $context): \DateTimeImmutable
    {
        if (preg_match(self::RFC3339_PATTERN, $value, $matches) !== 1) {
            throw new \InvalidArgumentException(\sprintf('%s must be an RFC 3339 datetime: "YYYY-MM-DDThh:mm:ss", an optional "." fraction, then "Z" or "+hh:mm"/"-hh:mm".', $context));
        }

        $secfrac = $matches['secfrac'];

        // RFC 3339's `"." 1*DIGIT` fraction is truncated to the six digits `u` reads.
        $parsed = '' === $secfrac
            ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $value)
            : \DateTimeImmutable::createFromFormat(
                self::MICROSECOND_FORMAT,
                $matches['datetime'].'.'.substr($secfrac, 0, self::MICROSECOND_DIGITS).$matches['offset'],
            );

        \assert($parsed instanceof \DateTimeImmutable);

        $errors = \DateTimeImmutable::getLastErrors();

        if (false !== $errors && [] !== $errors['warnings']) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be a valid ISO 8601 datetime: %s.',
                $context,
                implode('; ', $errors['warnings']),
            ));
        }

        return $parsed;
    }

    /**
     * Formats `$dateTime` as RFC 3339, emitting microsecond subseconds only when non-zero.
     *
     * @return non-empty-string
     */
    public static function format(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('u') === '000000'
            ? $dateTime->format(\DateTimeInterface::RFC3339)
            : $dateTime->format(self::MICROSECOND_FORMAT);
    }
}
