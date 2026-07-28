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
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * The result returned by the server for a `resources/templates/list` request.
 *
 * @extends PaginatedResult<array{
 *   _meta?: template-type<ResultMetaObject, MetaObject, 'T'>,
 *   resultType: non-empty-string,
 *   resourceTemplates: list<template-type<ResourceTemplate, Arrayable, 'T'>>,
 *   nextCursor?: non-empty-string,
 *   ttlMs: int,
 *   cacheScope: value-of<CacheScope>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#listresourcetemplatesresult
 */
final readonly class ListResourceTemplatesResult extends PaginatedResult implements ServerResult
{
    /**
     * @var list<ResourceTemplate>
     */
    public array $resourceTemplates;

    /**
     * @param list<ResourceTemplate> $resourceTemplates
     */
    public function __construct(
        array $resourceTemplates,
        int $ttlMs,
        CacheScope $cacheScope,
        ?Cursor $nextCursor = null,
        ResultMetaObject $meta = new ResultMetaObject(),
    ) {
        Assert::that($resourceTemplates)
            ->isList('"result.resourceTemplates" must be a list, non-list array given.')
            ->values()->isInstanceOf(ResourceTemplate::class)
        ;

        $this->resourceTemplates = $resourceTemplates;

        parent::__construct(ttlMs: $ttlMs, cacheScope: $cacheScope, nextCursor: $nextCursor, meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('resourceTemplates', '"result" is missing the required "resourceTemplates" key.');
        Assert::that($data['resourceTemplates'])
            ->isList('"result.resourceTemplates" must be a list, {type} given.')
            ->values()
            ->isArray('each "result.resourceTemplate" must be an object, {type} given.')
            ->isMap('each "result.resourceTemplate" must be a string-keyed object.')
        ;
        $resourceTemplates = array_map(ResourceTemplate::fromArray(...), $data['resourceTemplates']);

        Assert::that($data)->hasOffset('ttlMs', '"result" is missing the required "ttlMs" key.');
        $ttlMs = $data['ttlMs'];
        Assert::that($ttlMs)->isInt('"result.ttlMs" must be an integer, {type} given.');

        Assert::that($data)->hasOffset('cacheScope', '"result" is missing the required "cacheScope" key.');
        $cacheScope = EnumValueValidator::parse(CacheScope::class, $data['cacheScope'], '"result.cacheScope"');

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            $raw = $data['nextCursor'];
            Assert::that($raw)->isString('"result.nextCursor" must be a string, {type} given.');
            $nextCursor = new Cursor(cursor: $raw);
        }

        $meta = new ResultMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = ResultMetaObject::fromArray($data['_meta']);
        }

        return new self(
            resourceTemplates: $resourceTemplates,
            ttlMs: $ttlMs,
            cacheScope: $cacheScope,
            nextCursor: $nextCursor,
            meta: $meta,
        );
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
        $data['resourceTemplates'] = array_map(
            static fn(ResourceTemplate $template): array => $template->toArray(),
            $this->resourceTemplates,
        );

        if (null !== $this->nextCursor) {
            $data['nextCursor'] = $this->nextCursor->cursor;
        }

        $data['ttlMs'] = $this->ttlMs;
        $data['cacheScope'] = $this->cacheScope->value;

        return $data;
    }

    #[\Override]
    public function rebuildWithMeta(ResultMetaObject $meta): static
    {
        return new self(
            resourceTemplates: $this->resourceTemplates,
            ttlMs: $this->ttlMs,
            cacheScope: $this->cacheScope,
            nextCursor: $this->nextCursor,
            meta: $meta,
        );
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
