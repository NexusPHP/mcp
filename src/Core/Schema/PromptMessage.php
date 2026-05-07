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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;

/**
 * Describes a message returned as part of a prompt.
 *
 * This is similar to `SamplingMessage`, but also supports the embedding of resources from the MCP server.
 *
 * @implements Arrayable<array{
 *   content: template-type<AudioContent, Arrayable, 'T'>|template-type<EmbeddedResource, Arrayable, 'T'>|template-type<ImageContent, Arrayable, 'T'>|template-type<ResourceLink, Arrayable, 'T'>|template-type<TextContent, Arrayable, 'T'>,
 *   role: 'assistant'|'user',
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#promptmessage
 */
final readonly class PromptMessage implements Arrayable
{
    public function __construct(public Role $role, public AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent $content)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('role', 'PromptMessage wire data missing "role".');
        $role = $data['role'];
        Assert::that($role)->isString('PromptMessage wire "role" must be a string, {type} given.');

        Assert::that($data)->hasOffset('content', 'PromptMessage wire data missing "content".');
        Assert::that($data['content'])
            ->isArray('PromptMessage wire "content" must be an object, {type} given.')
            ->isMap('PromptMessage wire "content" must be a string-keyed object.')
        ;

        return new self(Role::from($role), self::dispatchContent($data['content']));
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'content' => $this->content->toArray(),
            'role' => $this->role->value,
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Discriminates the content payload by its `type` field and dispatches to
     * the matching `ContentBlock` subclass. Inlined here pending a second
     * consumer (`CallToolResult.content`); when that lands, extract to a
     * shared helper rather than duplicate this match.
     *
     * @param array<string, mixed> $data
     *
     * @throws ExpectationFailedException when `type` is missing or unknown
     */
    private static function dispatchContent(array $data): AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent
    {
        Assert::that($data)->hasOffset('type', 'PromptMessage content wire data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isString('PromptMessage content wire "type" must be a string, {type} given.');

        return match ($type) {
            TextContent::TYPE => TextContent::fromArray($data),
            ImageContent::TYPE => ImageContent::fromArray($data),
            AudioContent::TYPE => AudioContent::fromArray($data),
            ResourceLink::TYPE => ResourceLink::fromArray($data),
            EmbeddedResource::TYPE => EmbeddedResource::fromArray($data),
            default => throw new ExpectationFailedException(\sprintf(
                'PromptMessage content wire "type" must be one of "text", "image", "audio", "resource_link", "resource"; "%s" given.',
                $type,
            )),
        };
    }
}
