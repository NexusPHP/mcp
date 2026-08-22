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
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;

/**
 * The result returned by the server for a `resources/list` request.
 *
 * @extends PaginatedResult<array{
 *   _meta?: template-type<ResultMetaObject, MetaObject, 'T'>,
 *   resultType: non-empty-string,
 *   resources: list<template-type<Resource, Arrayable, 'T'>>,
 *   nextCursor?: non-empty-string,
 *   ttlMs: int,
 *   cacheScope: value-of<CacheScope>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#listresourcesresult
 */
final readonly class ListResourcesResult extends PaginatedResult implements ServerResult
{
    /**
     * @param list<Resource> $resources
     */
    public function __construct(
        public array $resources,
        int $ttlMs,
        CacheScope $cacheScope,
        ?Cursor $nextCursor = null,
        ResultMetaObject $meta = new GenericResultMetaObject(),
    ) {
        Assert::that($resources)
            ->isList('"result.resources" must be a list, non-list array given.')
            ->values()->isInstanceOf(Resource::class)
        ;

        parent::__construct(ttlMs: $ttlMs, cacheScope: $cacheScope, nextCursor: $nextCursor, meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('resources', '"result" is missing the required "resources" key.');
        Assert::that($data['resources'])
            ->isList('"result.resources" must be a list, {type} given.')
            ->values()
            ->isArray('each "result.resource" must be an object, {type} given.')
            ->isMap('each "result.resource" must be a string-keyed object.')
        ;
        $resources = array_map(Resource::fromArray(...), $data['resources']);

        Assert::that($data)->hasOffset('ttlMs', '"result" is missing the required "ttlMs" key.');
        $ttlMs = $data['ttlMs'];
        Assert::that($ttlMs)->isInt('"result.ttlMs" must be an integer, {type} given.');

        Assert::that($data)->hasOffset('cacheScope', '"result" is missing the required "cacheScope" key.');
        Assert::that($data['cacheScope'])->isOneOf(array_column(CacheScope::cases(), 'value'), '"result.cacheScope" must be one of {choices}, {value} given.');
        $cacheScope = CacheScope::from($data['cacheScope']);

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            $raw = $data['nextCursor'];
            Assert::that($raw)->isNonEmptyString('"result.nextCursor" must be a non-empty string, {type} given.');
            $nextCursor = new Cursor(cursor: $raw);
        }

        $meta = new GenericResultMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->not()->isNonEmptyList('"result._meta" must be a string-keyed object.')
            ;
            $meta = GenericResultMetaObject::fromArray($data['_meta']);
        }

        return new self(
            resources: $resources,
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
        $data['resources'] = array_map(
            static fn(Resource $resource): array => $resource->toArray(),
            $this->resources,
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
            resources: $this->resources,
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
