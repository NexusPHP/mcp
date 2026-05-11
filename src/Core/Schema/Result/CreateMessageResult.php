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

namespace Nexus\Mcp\Core\Schema\Result;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\JsonRpc\WireDiscriminator;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\Role;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Sampling\SamplingMessage;
use Nexus\Mcp\Core\Schema\Sampling\ToolResultContent;
use Nexus\Mcp\Core\Schema\Sampling\ToolUseContent;

/**
 * The client's response to a sampling/createMessage request from the server. The client should
 * inform the user before returning the sampled message, to allow them to inspect the response
 * (human in the loop) and decide whether to allow the server to see it.
 *
 * @phpstan-import-type ContentMember from SamplingMessage
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#createmessageresult
 */
final readonly class CreateMessageResult extends Result implements ClientResult
{
    /**
     * @var non-empty-string
     */
    public string $model;

    /**
     * @var ContentMember|list<ContentMember>
     */
    public array|AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $content;

    /**
     * @var null|non-empty-string
     */
    public ?string $stopReason;

    /**
     * @param ContentMember|list<ContentMember> $content
     */
    public function __construct(
        string $model,
        public Role $role,
        array|AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $content,
        ?string $stopReason = null,
        ?MetaObject $meta = null,
    ) {
        Assert::that($model)->isNonEmptyString('CreateMessageResult model must be a non-empty string.');
        Assert::that($stopReason)->nullOr()->isNonEmptyString('CreateMessageResult stopReason must be a non-empty string or null.');

        $this->model = $model;
        $this->content = $content;
        $this->stopReason = $stopReason;

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('model', 'CreateMessageResult wire data missing "model".');
        $model = $data['model'];
        Assert::that($model)->isString('CreateMessageResult wire "model" must be a string, {type} given.');

        Assert::that($data)->hasOffset('role', 'CreateMessageResult wire data missing "role".');
        $role = $data['role'];
        Assert::that($role)->isString('CreateMessageResult wire "role" must be a string, {type} given.');

        Assert::that($data)->hasOffset('content', 'CreateMessageResult wire data missing "content".');
        Assert::that($data['content'])->isArray('CreateMessageResult wire "content" must be an object or array, {type} given.');

        if ([] === $data['content'] || array_is_list($data['content'])) {
            $content = self::parseContentList($data['content']);
        } else {
            Assert::that($data['content'])->isMap('CreateMessageResult wire "content" must be a string-keyed object.');
            $content = self::parseContentBlock($data['content']);
        }

        $stopReason = $data['stopReason'] ?? null;
        Assert::that($stopReason)->nullOr()->isString('CreateMessageResult wire "stopReason" must be a string or null, {type} given.');

        $meta = MetaObject::parseFromWire($data, 'Result');

        return new self($model, Role::from($role), $content, $stopReason, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            ...parent::toArray(),
            'model' => $this->model,
            'role' => $this->role->value,
        ];

        if (\is_array($this->content)) {
            $data['content'] = array_map(
                static fn(AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent $block): array => $block->toArray(),
                $this->content,
            );
        } else {
            $data['content'] = $this->content->toArray();
        }

        if (null !== $this->stopReason) {
            $data['stopReason'] = $this->stopReason;
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
     * @param array<array-key, mixed> $value
     *
     * @return list<ContentMember>
     */
    private static function parseContentList(array $value): array
    {
        $blocks = [];

        foreach ($value as $entry) {
            Assert::that($entry)
                ->isArray('CreateMessageResult wire content entry must be an object, {type} given.')
                ->isMap('CreateMessageResult wire content entry must be a string-keyed object.')
            ;
            $blocks[] = self::parseContentBlock($entry);
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return ContentMember
     */
    private static function parseContentBlock(array $data): AudioContent|ImageContent|TextContent|ToolResultContent|ToolUseContent
    {
        $type = WireDiscriminator::readType($data, 'CreateMessageResult content');

        return match ($type) {
            TextContent::TYPE => TextContent::fromArray($data),
            ImageContent::TYPE => ImageContent::fromArray($data),
            AudioContent::TYPE => AudioContent::fromArray($data),
            ToolUseContent::TYPE => ToolUseContent::fromArray($data),
            ToolResultContent::TYPE => ToolResultContent::fromArray($data),
            default => throw WireDiscriminator::unknownType(
                'CreateMessageResult content',
                [TextContent::TYPE, ImageContent::TYPE, AudioContent::TYPE, ToolUseContent::TYPE, ToolResultContent::TYPE],
                $type,
            ),
        };
    }
}
