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
 * Reads typed fields out of a decoded OAuth JSON document, treating an absent field as `null` and a present
 * field of the wrong type as a fault.
 *
 * @internal
 */
final class MetadataReader
{
    /**
     * @param array<string, mixed> $data
     *
     * @return ?non-empty-string
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
        Assert::that($value)->not()->isNull(\sprintf('%s must carry a "%s" value.', $label, $key));

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return ?list<non-empty-string>
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
