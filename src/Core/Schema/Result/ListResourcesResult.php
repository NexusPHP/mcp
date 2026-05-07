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
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\Resource;

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
    public function __construct(array $resources, ?Cursor $nextCursor = null, ?Meta $meta = null)
    {
        Assert::that($resources)->isList('ListResourcesResult resources must be a list, got non-list array.');

        foreach ($resources as $resource) {
            Assert::that($resource)->isInstanceOf(Resource::class);
        }

        $this->resources = $resources;

        parent::__construct($nextCursor, $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('resources', 'ListResourcesResult wire data missing "resources".');
        Assert::that($data['resources'])->isArray('ListResourcesResult wire "resources" must be an array, {type} given.');

        $resources = [];

        foreach ($data['resources'] as $resourceData) {
            Assert::that($resourceData)
                ->isArray('ListResourcesResult wire resource entry must be an object, {type} given.')
                ->isMap('ListResourcesResult wire resource entry must be a string-keyed object.')
            ;
            $resources[] = Resource::fromArray($resourceData);
        }

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            $raw = $data['nextCursor'];
            Assert::that($raw)->isString('ListResourcesResult wire "nextCursor" must be a string, {type} given.');
            $nextCursor = new Cursor($raw);
        }

        $meta = Meta::parseFromWire($data, 'Result');

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
