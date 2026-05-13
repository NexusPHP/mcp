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
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;

/**
 * The server's response to a resources/templates/list request from the client.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#listresourcetemplatesresult
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
    public function __construct(array $resourceTemplates, ?Cursor $nextCursor = null, ?MetaObject $meta = null)
    {
        Assert::that($resourceTemplates)
            ->isList('ListResourceTemplatesResult resourceTemplates must be a list, got non-list array.')
            ->values()->isInstanceOf(ResourceTemplate::class)
        ;

        $this->resourceTemplates = $resourceTemplates;

        parent::__construct($nextCursor, $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('resourceTemplates', 'ListResourceTemplatesResult data missing "resourceTemplates".');
        Assert::that($data['resourceTemplates'])
            ->isList('ListResourceTemplatesResult "resourceTemplates" must be a list, {type} given.')
            ->values()
            ->isArray('ListResourceTemplatesResult resourceTemplate entry must be an object, {type} given.')
            ->isMap('ListResourceTemplatesResult resourceTemplate entry must be a string-keyed object.')
        ;
        $resourceTemplates = array_map(ResourceTemplate::fromArray(...), $data['resourceTemplates']);

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            $raw = $data['nextCursor'];
            Assert::that($raw)->isString('ListResourceTemplatesResult "nextCursor" must be a string, {type} given.');
            $nextCursor = new Cursor($raw);
        }

        $meta = MetaObject::parseFrom($data, 'Result');

        return new self($resourceTemplates, $nextCursor, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'resourceTemplates' => array_map(
                static fn(ResourceTemplate $template): array => $template->toArray(),
                $this->resourceTemplates,
            ),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
