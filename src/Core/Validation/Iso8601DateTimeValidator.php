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
 * Parses an ISO 8601 / RFC 3339 datetime string into `\DateTimeImmutable`,
 * trying the extended sub-second format first and falling back to the plain
 * RFC 3339 form. Rejects NUL bytes and surfaces parser warnings (e.g. silent
 * date overflow like `2026-13-45`) as `\InvalidArgumentException`.
 *
 * @internal
 */
final class Iso8601DateTimeValidator
{
    /**
     * @param non-empty-string $context Label prefix for the error message (e.g. "Task createdAt")
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $value, string $context): \DateTimeImmutable
    {
        if (str_contains($value, "\0")) {
            throw new \InvalidArgumentException(\sprintf('%s must not contain NULL bytes.', $context));
        }

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $value);

        if (false === $parsed) {
            $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $value);
        }

        if (false === $parsed) {
            throw new \InvalidArgumentException(\sprintf('%s must be a valid ISO 8601 datetime.', $context));
        }

        $errors = \DateTimeImmutable::getLastErrors();

        if (false !== $errors && [] !== $errors['warnings']) {
            throw new \InvalidArgumentException(implode('; ', $errors['warnings']).'.');
        }

        return $parsed;
    }

    /**
     * Formats `$dateTime` as RFC 3339, emitting `.vvv` subseconds only when non-zero.
     *
     * @return non-empty-string
     */
    public static function format(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('u') === '000000'
            ? $dateTime->format(\DateTimeInterface::RFC3339)
            : $dateTime->format(\DateTimeInterface::RFC3339_EXTENDED);
    }
}
