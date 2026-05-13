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

namespace Nexus\Mcp\Core\Schema\Sampling;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\JsonRpc\MessageDiscriminator;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\MetaObject;

/**
 * Describes a message issued to or received from an LLM API.
 *
 * @phpstan-type ContentMember AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent
 *
 * @implements Arrayable<array{
 *   content: array<string, mixed>|list<array<string, mixed>>,
 *   role: value-of<Role>,
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#samplingmessage
 */
final readonly class SamplingMessage implements Arrayable
{
    /**
     * @var ContentMember|list<ContentMember>
     */
    public array|AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $content;

    /**
     * @param ContentMember|list<ContentMember> $content
     */
    public function __construct(
        public Role $role,
        array|AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $content,
        public MetaObject $meta = new MetaObject(),
    ) {
        if (\is_array($content)) {
            Assert::that($content)->values()->isInstanceOf(SamplingMessageContentBlock::class);
        }

        $this->content = $content;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('role', 'SamplingMessage data missing "role".');
        $role = $data['role'];
        Assert::that($role)->isString('SamplingMessage "role" must be a string, {type} given.');

        Assert::that($data)->hasOffset('content', 'SamplingMessage data missing "content".');
        Assert::that($data['content'])->isArray('SamplingMessage "content" must be an object or array, {type} given.');

        if ([] === $data['content'] || array_is_list($data['content'])) {
            $content = self::parseContentList($data['content']);
        } else {
            Assert::that($data['content'])->isMap('SamplingMessage "content" must be a string-keyed object.');
            $content = self::parseContentBlock($data['content']);
        }

        $meta = MetaObject::parseFrom($data, 'SamplingMessage');

        return new self(Role::from($role), $content, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['role' => $this->role->value];

        if (\is_array($this->content)) {
            $data['content'] = array_map(
                static fn(AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $block): array => $block->toArray(),
                $this->content,
            );
        } else {
            $data['content'] = $this->content->toArray();
        }

        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->toArray();

        if (\is_array($this->content)) {
            $data['content'] = array_map(
                static fn(AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $block): array => $block->jsonSerialize(),
                $this->content,
            );
        } else {
            $data['content'] = $this->content->jsonSerialize();
        }

        return $data;
    }

    /**
     * @param list<mixed> $value
     *
     * @return list<ContentMember>
     */
    private static function parseContentList(array $value): array
    {
        Assert::that($value)
            ->values()
            ->isArray('SamplingMessage content entry must be an object, {type} given.')
            ->isMap('SamplingMessage content entry must be a string-keyed object.')
        ;

        return array_map(self::parseContentBlock(...), $value);
    }

    /**
     * Discriminates a single sampling content block by its `type` field. The
     * sampling union differs from `ContentBlock`: it includes `ToolUseContent`
     * and `ToolResultContent` instead of `ResourceLink` and `EmbeddedResource`.
     *
     * @param array<string, mixed> $data
     *
     * @return ContentMember
     */
    private static function parseContentBlock(array $data): AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent
    {
        $type = MessageDiscriminator::readType($data, 'SamplingMessage content');

        return match ($type) {
            TextContent::TYPE => TextContent::fromArray($data),
            ImageContent::TYPE => ImageContent::fromArray($data),
            AudioContent::TYPE => AudioContent::fromArray($data),
            ToolUseContent::TYPE => ToolUseContent::fromArray($data),
            ToolResultContent::TYPE => ToolResultContent::fromArray($data),
            default => throw MessageDiscriminator::unknownType(
                'SamplingMessage content',
                [TextContent::TYPE, ImageContent::TYPE, AudioContent::TYPE, ToolUseContent::TYPE, ToolResultContent::TYPE],
                $type,
            ),
        };
    }
}
