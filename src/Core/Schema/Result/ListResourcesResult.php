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
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Validation\EnumValueValidator;

/**
 * The result returned by the server for a `resources/list` request.
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#listresourcesresult
 */
final readonly class ListResourcesResult extends PaginatedResult implements ServerResult
{
    /**
     * @var list<Resource>
     */
    public array $resources;

    /**
     * @param list<Resource> $resources
     */
    public function __construct(
        array $resources,
        int $ttlMs,
        CacheScope $cacheScope,
        ?Cursor $nextCursor = null,
        MetaObject $meta = new MetaObject(),
    ) {
        Assert::that($resources)
            ->isList('"result.resources" must be a list, non-list array given.')
            ->values()->isInstanceOf(Resource::class)
        ;

        $this->resources = $resources;

        parent::__construct($ttlMs, $cacheScope, $nextCursor, $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('resources', '"result" missing the required "resources" key.');
        Assert::that($data['resources'])
            ->isList('"result.resources" must be a list, {type} given.')
            ->values()
            ->isArray('each "result.resource" must be an object, {type} given.')
            ->isMap('each "result.resource" must be a string-keyed object.')
        ;
        $resources = array_map(Resource::fromArray(...), $data['resources']);

        Assert::that($data)->hasOffset('ttlMs', '"result" missing the required "ttlMs" key.');
        $ttlMs = $data['ttlMs'];
        Assert::that($ttlMs)->isInt('"result.ttlMs" must be an integer, {type} given.');

        Assert::that($data)->hasOffset('cacheScope', '"result" missing the required "cacheScope" key.');
        $cacheScope = EnumValueValidator::parse(CacheScope::class, $data['cacheScope'], '"result.cacheScope"');

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            $raw = $data['nextCursor'];
            Assert::that($raw)->isString('"result.nextCursor" must be a string, {type} given.');
            $nextCursor = new Cursor(cursor: $raw);
        }

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
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
        return [
            ...parent::toArray(),
            'resources' => array_map(static fn(Resource $resource): array => $resource->toArray(), $this->resources),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
