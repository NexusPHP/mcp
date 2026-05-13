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
use Nexus\Mcp\Core\JsonRpc\ContentBlockDispatcher;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\MetaObject;

/**
 * The result of a tool use, provided by the user back to the assistant.
 *
 * @implements Arrayable<array{
 *   content: list<
 *     template-type<TextContent, Arrayable, 'T'>
 *     | template-type<ImageContent, Arrayable, 'T'>
 *     | template-type<AudioContent, Arrayable, 'T'>
 *     | template-type<ResourceLink, Arrayable, 'T'>
 *     | template-type<EmbeddedResource, Arrayable, 'T'>
 *   >,
 *   toolUseId: non-empty-string,
 *   type: 'tool_result',
 *   isError?: bool,
 *   structuredContent?: array<string, mixed>,
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#toolresultcontent
 */
final readonly class ToolResultContent implements Arrayable, SamplingMessageContentBlock
{
    public const string TYPE = 'tool_result';

    /**
     * @var non-empty-string
     */
    public string $toolUseId;

    /**
     * @param list<AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent> $content
     * @param null|array<string, mixed>                                                 $structuredContent
     */
    public function __construct(
        string $toolUseId,
        public array $content,
        public ?bool $isError = null,
        public ?array $structuredContent = null,
        public ?MetaObject $meta = null,
    ) {
        Assert::that($toolUseId)->isNonEmptyString('ToolResultContent toolUseId must be a non-empty string.');
        Assert::that($content)->values()->isInstanceOf(Arrayable::class);

        $this->toolUseId = $toolUseId;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('type', 'ToolResultContent data missing "type".');
        $type = $data['type'];
        Assert::that($type)->isIdentical(self::TYPE, \sprintf('ToolResultContent "type" must be "%s", {value} given.', self::TYPE));

        Assert::that($data)->hasOffset('toolUseId', 'ToolResultContent data missing "toolUseId".');
        $toolUseId = $data['toolUseId'];
        Assert::that($toolUseId)->isString('ToolResultContent "toolUseId" must be a string, {type} given.');

        Assert::that($data)->hasOffset('content', 'ToolResultContent data missing "content".');
        Assert::that($data['content'])
            ->isList('ToolResultContent "content" must be a list, {type} given.')
            ->values()
            ->isArray('ToolResultContent content entry must be an object, {type} given.')
            ->isMap('ToolResultContent content entry must be a string-keyed object.')
        ;
        $content = array_map(
            static fn(array $entry): AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent => ContentBlockDispatcher::fromArray($entry, 'ToolResultContent content'),
            $data['content'],
        );

        $isError = $data['isError'] ?? null;
        Assert::that($isError)->nullOr()->isBool('ToolResultContent "isError" must be a bool or null, {type} given.');

        $structuredContent = null;

        if (\array_key_exists('structuredContent', $data)) {
            Assert::that($data['structuredContent'])
                ->isArray('ToolResultContent "structuredContent" must be an object, {type} given.')
                ->isMap('ToolResultContent "structuredContent" must be a string-keyed object.')
            ;
            $structuredContent = $data['structuredContent'];
        }

        $meta = MetaObject::parseFrom($data, 'ToolResultContent');

        return new self($toolUseId, $content, $isError, $structuredContent, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            'content' => array_map(static fn(AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent $block): array => $block->toArray(), $this->content),
            'toolUseId' => $this->toolUseId,
            'type' => self::TYPE,
        ];

        if (null !== $this->isError) {
            $data['isError'] = $this->isError;
        }

        if (null !== $this->structuredContent) {
            $data['structuredContent'] = $this->structuredContent;
        }

        if (null !== $this->meta) {
            $meta = $this->meta->toArray();

            if ([] !== $meta) {
                $data['_meta'] = $meta;
            }
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->toArray();

        if (null !== $this->structuredContent && [] === $this->structuredContent) {
            $data['structuredContent'] = new \stdClass();
        }

        return $data;
    }
}
