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

use Nexus\Assert\Assert;

/**
 * Parses an ISO 8601 / RFC 3339 datetime string into `\DateTimeImmutable`.
 *
 * @internal
 */
final class Iso8601DateTimeValidator
{
    private const string SECFRAC_PATTERN = '/\A(?P<datetime>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\.(?P<secfrac>\d+)(?P<offset>.*)\z/';
    private const string MICROSECOND_FORMAT = 'Y-m-d\TH:i:s.uP';
    private const int MICROSECOND_DIGITS = 6;

    /**
     * @param non-empty-string $context Label prefix for the error message (e.g. "Task createdAt")
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $value, string $context): \DateTimeImmutable
    {
        Assert::that($value)->not()->contains("\0", \sprintf('%s must not contain NULL bytes.', $context));

        $parsed = \DateTimeImmutable::createFromFormat(self::MICROSECOND_FORMAT, self::truncateSecfrac($value));

        if (false === $parsed) {
            $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $value);
        }

        if (false === $parsed) {
            throw new \InvalidArgumentException(\sprintf('%s must be a valid ISO 8601 datetime.', $context));
        }

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

    /**
     * Truncates RFC 3339's `"." 1*DIGIT` fractional second to the six digits `u` reads.
     */
    private static function truncateSecfrac(string $value): string
    {
        if (preg_match(self::SECFRAC_PATTERN, $value, $matches) !== 1) {
            return $value;
        }

        return $matches['datetime'].'.'.substr($matches['secfrac'], 0, self::MICROSECOND_DIGITS).$matches['offset'];
    }
}
