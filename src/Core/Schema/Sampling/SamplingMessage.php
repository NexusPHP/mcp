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
use Nexus\Mcp\Core\JsonRpc\SamplingContentDispatcher;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

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
        Assert::that($data)->hasOffset('role', 'sampling message missing the required "role" key.');
        $role = EnumValueValidator::parse(Role::class, $data['role'], 'sampling message "role"');

        Assert::that($data)->hasOffset('content', 'sampling message missing the required "content" key.');
        Assert::that($data['content'])->isArray('sampling message "content" must be an object or array, {type} given.');

        if ([] === $data['content'] || array_is_list($data['content'])) {
            Assert::that($data['content'])
                ->values()
                ->isArray('each sampling message "content" must be an object, {type} given.')
                ->isMap('each sampling message "content" must be a string-keyed object.')
            ;
            $content = array_map(
                static fn(array $entry): AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent => SamplingContentDispatcher::fromArray($entry, 'sampling message "content"'),
                $data['content'],
            );
        } else {
            Assert::that($data['content'])->isMap('sampling message "content" must be a string-keyed object.');
            $content = SamplingContentDispatcher::fromArray($data['content'], 'sampling message "content"');
        }

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('sampling message "_meta" must be an object, {type} given.')
                ->isMap('sampling message "_meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self($role, $content, $meta);
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
        $data = ['role' => $this->role->value];

        if (\is_array($this->content)) {
            $data['content'] = array_map(
                static fn(AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $block): array => $block->jsonSerialize(),
                $this->content,
            );
        } else {
            $data['content'] = $this->content->jsonSerialize();
        }

        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        return $data;
    }
}
