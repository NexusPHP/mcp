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
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Resource\Resource;

/**
 * The server's response to a resources/list request from the client.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#listresourcesresult
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
    public function __construct(array $resources, ?Cursor $nextCursor = null, MetaObject $meta = new MetaObject())
    {
        Assert::that($resources)
            ->isList('ListResourcesResult resources must be a list, got non-list array.')
            ->values()->isInstanceOf(Resource::class)
        ;

        $this->resources = $resources;

        parent::__construct($nextCursor, $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('resources', 'ListResourcesResult data missing "resources".');
        Assert::that($data['resources'])
            ->isList('ListResourcesResult "resources" must be a list, {type} given.')
            ->values()
            ->isArray('ListResourcesResult resource entry must be an object, {type} given.')
            ->isMap('ListResourcesResult resource entry must be a string-keyed object.')
        ;
        $resources = array_map(Resource::fromArray(...), $data['resources']);

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            $raw = $data['nextCursor'];
            Assert::that($raw)->isString('ListResourcesResult "nextCursor" must be a string, {type} given.');
            $nextCursor = new Cursor($raw);
        }

        $meta = MetaObject::parseFrom($data, 'Result');

        return new self($resources, $nextCursor, $meta);
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
