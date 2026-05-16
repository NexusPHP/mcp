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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;

/**
 * Discriminates a `ContentBlock` payload by its `type` field and
 * dispatches to the matching concrete subclass. Used by `PromptMessage` and
 * `CallToolResult` (the two spec shapes carrying `ContentBlock` unions).
 *
 * @internal
 */
final class ContentBlockDispatcher
{
    /**
     * @param array<string, mixed> $data
     * @param non-empty-string     $context Prefix used in error messages
     *
     * @throws ExpectationFailedException when `type` is missing or unknown
     */
    public static function fromArray(array $data, string $context): AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent
    {
        $type = MessageDiscriminator::readType($data, $context);

        return match ($type) {
            TextContent::TYPE => TextContent::fromArray($data),
            ImageContent::TYPE => ImageContent::fromArray($data),
            AudioContent::TYPE => AudioContent::fromArray($data),
            ResourceLink::TYPE => ResourceLink::fromArray($data),
            EmbeddedResource::TYPE => EmbeddedResource::fromArray($data),
            default => throw MessageDiscriminator::unknownType(
                $context,
                [TextContent::TYPE, ImageContent::TYPE, AudioContent::TYPE, ResourceLink::TYPE, EmbeddedResource::TYPE],
                $type,
            ),
        };
    }
}
