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

use Nexus\Mcp\Core\Schema\ContentBlock;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;

/**
 * Discriminator for `ContentBlock` payloads, keyed on the `type` field.
 *
 * @internal
 */
final class ContentBlockDispatcher
{
    private const array ALLOWED_CONTENT_BLOCK_TYPES = [
        TextContent::TYPE,
        ImageContent::TYPE,
        AudioContent::TYPE,
        ResourceLink::TYPE,
        EmbeddedResource::TYPE,
    ];

    /**
     * @param array<string, mixed> $data
     * @param non-empty-string     $context Prefix used in error messages
     *
     * @return AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data, string $context): ContentBlock
    {
        $type = MessageDiscriminator::readType($data, $context);

        return match ($type) {
            TextContent::TYPE => TextContent::fromArray($data),
            ImageContent::TYPE => ImageContent::fromArray($data),
            AudioContent::TYPE => AudioContent::fromArray($data),
            ResourceLink::TYPE => ResourceLink::fromArray($data),
            EmbeddedResource::TYPE => EmbeddedResource::fromArray($data),
            default => throw MessageDiscriminator::buildUnknownTypeError($context, self::ALLOWED_CONTENT_BLOCK_TYPES, $type),
        };
    }
}
