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
use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Core\Schema\ContentBlock;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Request\InputRequest;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;

/**
 * Decoder for the payloads whose concrete subtype is picked by a discriminating field.
 *
 * @internal
 */
final class MessageDiscriminator
{
    private const array ALLOWED_CONTENT_BLOCK_TYPES = [
        TextContent::TYPE,
        ImageContent::TYPE,
        AudioContent::TYPE,
        ResourceLink::TYPE,
        EmbeddedResource::TYPE,
    ];

    /**
     * Decodes a `ContentBlock`, keyed on its `type` field.
     *
     * @param array<string, mixed> $data
     * @param non-empty-string     $context Prefix used in error messages
     *
     * @return AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent
     *
     * @throws \InvalidArgumentException
     */
    public static function decodeContentBlock(array $data, string $context): ContentBlock
    {
        $type = self::readType($data, $context);

        return match ($type) {
            TextContent::TYPE => TextContent::fromArray($data),
            ImageContent::TYPE => ImageContent::fromArray($data),
            AudioContent::TYPE => AudioContent::fromArray($data),
            ResourceLink::TYPE => ResourceLink::fromArray($data),
            EmbeddedResource::TYPE => EmbeddedResource::fromArray($data),
            default => throw self::buildUnknownTypeError($context, self::ALLOWED_CONTENT_BLOCK_TYPES, $type),
        };
    }

    /**
     * Decodes `ResourceContents`, keyed on the presence of `text` versus `blob`.
     *
     * @param array<string, mixed> $data
     * @param non-empty-string     $context Prefix used in error messages
     *
     * @return BlobResourceContents|TextResourceContents
     *
     * @throws \InvalidArgumentException
     */
    public static function decodeResourceContents(array $data, string $context): ResourceContents
    {
        $hasText = \array_key_exists('text', $data);
        $hasBlob = \array_key_exists('blob', $data);

        if ($hasText && $hasBlob) {
            throw new \InvalidArgumentException(\sprintf('%s data must not have both "text" and "blob".', $context));
        }

        if ($hasText) {
            return TextResourceContents::fromArray($data);
        }

        if ($hasBlob) {
            return BlobResourceContents::fromArray($data);
        }

        throw new \InvalidArgumentException(\sprintf('%s data must have either "text" or "blob".', $context));
    }

    /**
     * Decodes an `InputRequest`, keyed on its `method` field.
     *
     * @param array<string, mixed> $request
     */
    public static function decodeInputRequest(array $request): InputRequest
    {
        Assert::that($request)->hasOffset(
            'method',
            'each "result.inputRequests" entry is missing the required "method" key.',
        );

        if (ElicitRequest::getMethod() === $request['method']) {
            return ElicitRequest::fromArray($request);
        }

        throw new \InvalidArgumentException(\sprintf(
            'each "result.inputRequests" entry must use a supported input-request method, %s given.',
            SafeDisplay::sanitise(var_export($request['method'], true)),
        ));
    }

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
    public static function buildUnknownTypeError(
        string $context,
        array $allowedTypes,
        string $given,
    ): \InvalidArgumentException {
        return new \InvalidArgumentException(\sprintf(
            '%s "type" must be one of "%s", \'%s\' given.',
            $context,
            implode('", "', $allowedTypes),
            $given,
        ));
    }
}
