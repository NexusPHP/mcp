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

use Nexus\Assert\Assert;

/**
 * Shared helpers for JSON shapes that discriminate concrete subtypes by a `type` field.
 *
 * @internal
 */
final class MessageDiscriminator
{
    /**
     * Reads and validates the discriminator `type` field from a payload, `$context` scoping both error
     * messages to the calling shape.
     *
     * @param array<string, mixed> $data
     * @param non-empty-string     $context
     */
    public static function readType(array $data, string $context): string
    {
        Assert::that($data)->hasOffset('type', \sprintf('%s data missing "type".', $context));
        $type = $data['type'];
        Assert::that($type)->isString(\sprintf('%s "type" must be a string, {type} given.', $context));

        return $type;
    }

    /**
     * @param non-empty-string                 $context
     * @param non-empty-list<non-empty-string> $allowedTypes
     */
    public static function buildUnknownTypeError(string $context, array $allowedTypes, string $given): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf(
            '%s "type" must be one of "%s", \'%s\' given.',
            $context,
            implode('", "', $allowedTypes),
            $given,
        ));
    }
}
