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
use Nexus\Assert\ExpectationFailedException;

/**
 * Shared helpers for JSON shapes that discriminate concrete subtypes by a
 * `type` field. Used by `PromptMessage` to dispatch over `ContentBlock`
 * concretes and by `CompleteRequestParams` to dispatch between the two
 * `Reference` shapes.
 *
 * @internal
 */
final class MessageDiscriminator
{
    /**
     * Reads and validates the discriminator `type` field from a payload.
     * The `$context` prefix scopes both error messages to the calling shape
     * (e.g. `"PromptMessage content"`, `"CompleteRequestParams ref"`).
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
     * Builds the exception for a payload whose `type` did not match any
     * allowed discriminator. The allowed values appear quoted in the message,
     * e.g. `must be one of "ref/prompt", "ref/resource"`.
     *
     * @param non-empty-string                 $context
     * @param non-empty-list<non-empty-string> $allowedTypes
     */
    public static function unknownType(string $context, array $allowedTypes, string $given): ExpectationFailedException
    {
        return new ExpectationFailedException(\sprintf(
            '%s "type" must be one of "%s"; "%s" given.',
            $context,
            implode('", "', $allowedTypes),
            $given,
        ));
    }
}
