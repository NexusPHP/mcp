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
use Nexus\Mcp\Core\JsonRpc\ContentBlockDispatcher;
use Nexus\Mcp\Core\Schema\ContentBlock;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;

/**
 * The server's response to a tool call.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#calltoolresult
 */
final readonly class CallToolResult extends Result implements ServerResult
{
    /**
     * @var list<AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent>
     */
    public array $content;

    /**
     * @var null|array<string, mixed>
     */
    public ?array $structuredContent;

    /**
     * @param list<AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent> $content
     * @param null|array<string, mixed>                                                 $structuredContent
     */
    public function __construct(
        array $content,
        ?array $structuredContent = null,
        public ?bool $isError = null,
        MetaObject $meta = new MetaObject(),
    ) {
        Assert::that($content)
            ->isList('CallToolResult content must be a list, got non-list array.')
            ->values()->isInstanceOf(ContentBlock::class)
        ;

        if (null !== $structuredContent) {
            Assert::that($structuredContent)->isMap('CallToolResult structuredContent must be a string-keyed map.');
        }

        $this->content = $content;
        $this->structuredContent = $structuredContent;

        parent::__construct($meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('content', 'CallToolResult data missing "content".');
        Assert::that($data['content'])
            ->isList('CallToolResult "content" must be a list, {type} given.')
            ->values()
            ->isArray('CallToolResult content entry must be an object, {type} given.')
            ->isMap('CallToolResult content entry must be a string-keyed object.')
        ;
        $content = array_map(
            static fn(array $block): AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent => ContentBlockDispatcher::fromArray($block, 'CallToolResult content'),
            $data['content'],
        );

        $structuredContent = null;

        if (\array_key_exists('structuredContent', $data)) {
            Assert::that($data['structuredContent'])
                ->isArray('CallToolResult "structuredContent" must be an object, {type} given.')
                ->isMap('CallToolResult "structuredContent" must be a string-keyed object.')
            ;
            $structuredContent = $data['structuredContent'];
        }

        $isError = $data['isError'] ?? null;
        Assert::that($isError)->nullOr()->isBool('CallToolResult "isError" must be a bool or null, {type} given.');

        $meta = MetaObject::parseFrom($data, 'Result');

        return new self($content, $structuredContent, $isError, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [
            ...parent::toArray(),
            'content' => array_map(
                static fn(AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent $block): array => $block->toArray(),
                $this->content,
            ),
        ];

        if (null !== $this->structuredContent) {
            $data['structuredContent'] = $this->structuredContent;
        }

        if (null !== $this->isError) {
            $data['isError'] = $this->isError;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
