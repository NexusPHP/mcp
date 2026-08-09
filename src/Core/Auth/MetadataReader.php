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

namespace Nexus\Mcp\Core\Auth;

use Nexus\Assert\Assert;

/**
 * Typed field reader for a decoded OAuth JSON document.
 *
 * @internal
 */
final class MetadataReader
{
    /**
     * @see https://datatracker.ietf.org/doc/html/rfc6749#appendix-A.7
     */
    private const string ERROR_FIELD_GRAMMAR = '/[^\x20\x21\x23-\x5B\x5D-\x7E]/';

    private const int MAX_ERROR_FIELD_LENGTH = 200;

    /**
     * @param array<string, mixed> $data
     *
     * @return null|non-empty-string
     */
    public static function readString(array $data, string $key, string $label): ?string
    {
        if (! \array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        Assert::that($value)->isNonEmptyString(\sprintf('%s "%s" must be a non-empty string, {type} given.', $label, $key));

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return non-empty-string
     */
    public static function readRequiredString(array $data, string $key, string $label): string
    {
        $value = self::readString($data, $key, $label);
        Assert::that($value)->isNonEmptyString(\sprintf('%s must carry a "%s" value.', $label, $key));

        return $value;
    }

    /**
     * Reads an RFC 6749 error field, held to the RFC's grammar and a bounded length.
     *
     * @param array<array-key, mixed> $data
     *
     * @return null|non-empty-string
     */
    public static function readErrorField(array $data, string $key, string $label): ?string
    {
        if (! \array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        Assert::that($value)->isNonEmptyString(\sprintf('%s "%s" must be a non-empty string, {type} given.', $label, $key));
        $held = substr((string) preg_replace(self::ERROR_FIELD_GRAMMAR, '', $value), 0, self::MAX_ERROR_FIELD_LENGTH);

        return '' === $held ? null : $held;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return null|list<non-empty-string>
     */
    public static function readStringList(array $data, string $key, string $label): ?array
    {
        if (! \array_key_exists($key, $data)) {
            return null;
        }

        $entries = $data[$key];
        Assert::that($entries)->isList(\sprintf('%s "%s" must be a list, {type} given.', $label, $key));

        $values = [];

        foreach ($entries as $entry) {
            Assert::that($entry)->isNonEmptyString(\sprintf('%s "%s" must hold only non-empty strings, {type} given.', $label, $key));
            $values[] = $entry;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function readInt(array $data, string $key, string $label): ?int
    {
        if (! \array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        Assert::that($value)->isInt(\sprintf('%s "%s" must be an integer, {type} given.', $label, $key));

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function readBool(array $data, string $key, string $label): ?bool
    {
        if (! \array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        Assert::that($value)->isBool(\sprintf('%s "%s" must be a boolean, {type} given.', $label, $key));

        return $value;
    }
}
