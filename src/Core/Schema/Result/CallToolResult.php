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
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\ContentBlock;
use Nexus\Mcp\Core\Schema\ContentBlock\AudioContent;
use Nexus\Mcp\Core\Schema\ContentBlock\EmbeddedResource;
use Nexus\Mcp\Core\Schema\ContentBlock\ImageContent;
use Nexus\Mcp\Core\Schema\ContentBlock\ResourceLink;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\ResultMetaObject;

/**
 * The result returned by the server for a `tools/call` request.
 *
 * @extends Result<array{
 *   _meta?: template-type<ResultMetaObject, Arrayable, 'T'>,
 *   resultType: non-empty-string,
 *   content: list<template-type<AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent, Arrayable, 'T'>>,
 *   structuredContent?: array<string, mixed>,
 *   isError?: bool,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#calltoolresult
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
        ResultMetaObject $meta = new ResultMetaObject(),
    ) {
        Assert::that($content)
            ->isList('"result.content" must be a list, non-list array given.')
            ->values()->isInstanceOf(ContentBlock::class)
        ;
        Assert::that($structuredContent)
            ->nullOr()
            ->isMap('"result.structuredContent" must be a string-keyed map or null.')
        ;

        $this->content = $content;
        $this->structuredContent = $structuredContent;

        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('content', '"result" is missing the required "content" key.');
        Assert::that($data['content'])
            ->isList('"result.content" must be a list, {type} given.')
            ->values()
            ->isArray('each "result.content" must be an object, {type} given.')
            ->isMap('each "result.content" must be a string-keyed object.')
        ;
        $content = array_map(
            static fn(array $block): AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent => ContentBlockDispatcher::fromArray($block, 'CallToolResult content'),
            $data['content'],
        );

        $structuredContent = null;

        if (\array_key_exists('structuredContent', $data)) {
            Assert::that($data['structuredContent'])
                ->isArray('"result.structuredContent" must be an object, {type} given.')
                ->isMap('"result.structuredContent" must be a string-keyed object.')
            ;
            $structuredContent = $data['structuredContent'];
        }

        $isError = $data['isError'] ?? null;
        Assert::that($isError)->nullOr()->isBool('"result.isError" must be a bool or null, {type} given.');

        $meta = new ResultMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = ResultMetaObject::fromArray($data['_meta']);
        }

        return new self(content: $content, structuredContent: $structuredContent, isError: $isError, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];
        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        $data['resultType'] = self::getResultType();
        $data['content'] = array_map(
            static fn(AudioContent|EmbeddedResource|ImageContent|ResourceLink|TextContent $block): array => $block->toArray(),
            $this->content,
        );

        if (null !== $this->structuredContent) {
            $data['structuredContent'] = $this->structuredContent;
        }

        if (null !== $this->isError) {
            $data['isError'] = $this->isError;
        }

        return $data;
    }

    #[\Override]
    public function rebuildWithMeta(ResultMetaObject $meta): static
    {
        return new self(
            content: $this->content,
            structuredContent: $this->structuredContent,
            isError: $this->isError,
            meta: $meta,
        );
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
