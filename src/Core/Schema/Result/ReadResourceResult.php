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
use Nexus\Mcp\Core\JsonRpc\ResourceContentsDispatcher;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\ResultMetaObject;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * The result returned by the server for a `resources/read` request.
 *
 * @extends CacheableResult<array{
 *   _meta?: template-type<ResultMetaObject, Arrayable, 'T'>,
 *   resultType: non-empty-string,
 *   contents: list<template-type<BlobResourceContents|TextResourceContents, Arrayable, 'T'>>,
 *   ttlMs: int,
 *   cacheScope: value-of<CacheScope>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#readresourceresult
 */
final readonly class ReadResourceResult extends CacheableResult implements ServerResult
{
    /**
     * @var list<BlobResourceContents|TextResourceContents>
     */
    public array $contents;

    /**
     * @param list<BlobResourceContents|TextResourceContents> $contents
     */
    public function __construct(
        array $contents,
        int $ttlMs,
        CacheScope $cacheScope,
        ResultMetaObject $meta = new ResultMetaObject(),
    ) {
        Assert::that($contents)
            ->isList('"result.contents" must be a list, non-list array given.')
            ->values()->isInstanceOf(ResourceContents::class)
        ;

        $this->contents = $contents;

        parent::__construct(ttlMs: $ttlMs, cacheScope: $cacheScope, meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('contents', '"result" is missing the required "contents" key.');
        Assert::that($data['contents'])
            ->isList('"result.contents" must be a list, {type} given.')
            ->values()
            ->isArray('each "result.contents" must be an object, {type} given.')
            ->isMap('each "result.contents" must be a string-keyed object.')
        ;
        $contents = array_map(
            static fn(array $entry): BlobResourceContents|TextResourceContents => ResourceContentsDispatcher::fromArray($entry, 'ReadResourceResult contents'),
            $data['contents'],
        );

        Assert::that($data)->hasOffset('ttlMs', '"result" is missing the required "ttlMs" key.');
        $ttlMs = $data['ttlMs'];
        Assert::that($ttlMs)->isInt('"result.ttlMs" must be an integer, {type} given.');

        Assert::that($data)->hasOffset('cacheScope', '"result" is missing the required "cacheScope" key.');
        $cacheScope = EnumValueValidator::parse(CacheScope::class, $data['cacheScope'], '"result.cacheScope"');

        $meta = new ResultMetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = ResultMetaObject::fromArray($data['_meta']);
        }

        return new self(contents: $contents, ttlMs: $ttlMs, cacheScope: $cacheScope, meta: $meta);
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
        $data['contents'] = array_map(static fn(ResourceContents $entry): array => $entry->toArray(), $this->contents);
        $data['ttlMs'] = $this->ttlMs;
        $data['cacheScope'] = $this->cacheScope->value;

        return $data;
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
