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
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Sampling\SamplingMessageContentBlock;
use Nexus\Mcp\Core\Schema\Sampling\ToolResultContent;
use Nexus\Mcp\Core\Schema\Sampling\ToolUseContent;

/**
 * Discriminates a sampling content payload by its `type` field and dispatches to the matching concrete subclass.
 *
 * @internal
 */
final class SamplingContentDispatcher
{
    private const array ALLOWED_SAMPLING_CONTENT_TYPES = [
        TextContent::TYPE,
        ImageContent::TYPE,
        AudioContent::TYPE,
        ToolUseContent::TYPE,
        ToolResultContent::TYPE,
    ];

    /**
     * @param array<string, mixed> $data
     * @param non-empty-string     $context Prefix used in error messages
     *
     * @return AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent
     *
     * @throws ExpectationFailedException
     */
    public static function fromArray(array $data, string $context): SamplingMessageContentBlock
    {
        $type = MessageDiscriminator::readType($data, $context);

        return match ($type) {
            TextContent::TYPE => TextContent::fromArray($data),
            ImageContent::TYPE => ImageContent::fromArray($data),
            AudioContent::TYPE => AudioContent::fromArray($data),
            ToolUseContent::TYPE => ToolUseContent::fromArray($data),
            ToolResultContent::TYPE => ToolResultContent::fromArray($data),
            default => throw MessageDiscriminator::buildUnknownTypeError($context, self::ALLOWED_SAMPLING_CONTENT_TYPES, $type),
        };
    }
}
