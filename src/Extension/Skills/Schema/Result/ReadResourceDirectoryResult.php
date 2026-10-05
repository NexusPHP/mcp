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

namespace Nexus\Mcp\Extension\Skills\Schema\Result;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\ServerResult;

/**
 * The result returned by the server for a `resources/directory/read` request.
 *
 * @extends Result<array{
 *   _meta?: template-type<ResultMetaObject, MetaObject, 'T'>,
 *   resultType: non-empty-string,
 *   resources: list<template-type<Resource, Arrayable, 'T'>>,
 *   nextCursor?: non-empty-string,
 * }>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/seps/2640-skills-extension.md
 */
final readonly class ReadResourceDirectoryResult extends Result implements ServerResult
{
    /**
     * @param list<Resource> $resources The directory's direct children
     */
    public function __construct(
        public array $resources,
        public ?Cursor $nextCursor = null,
        ResultMetaObject $meta = new GenericResultMetaObject(),
    ) {
        Assert::that($resources)
            ->isList('"result.resources" must be a list, non-list array given.')
            ->values()->isInstanceOf(Resource::class)
        ;

        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('resources', '"result" is missing the required "resources" key.');
        Assert::that($data['resources'])
            ->isList('"result.resources" must be a list, {type} given.')
            ->values()
            ->isArray('each "result.resources" entry must be an object, {type} given.')
            ->isMap('each "result.resources" entry must be a string-keyed object.')
        ;
        $resources = array_map(Resource::fromArray(...), $data['resources']);

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

        return new self(resources: $resources, nextCursor: $nextCursor, meta: $meta);
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
        $data['resources'] = array_map(static fn(Resource $resource): array => $resource->toArray(), $this->resources);

        if (null !== $this->nextCursor) {
            $data['nextCursor'] = $this->nextCursor->cursor;
        }

        return $data;
    }

    #[\Override]
    public function rebuildWithMeta(ResultMetaObject $meta): static
    {
        return new self(resources: $this->resources, nextCursor: $this->nextCursor, meta: $meta);
    }

    #[\Override]
    protected function getResultType(): string
    {
        return ResultType::Complete->value;
    }
}
