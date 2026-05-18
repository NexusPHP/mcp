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
use Nexus\Mcp\Core\Schema\Tool\Tool;

/**
 * The server's response to a tools/list request from the client.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#listtoolsresult
 */
final readonly class ListToolsResult extends PaginatedResult implements ServerResult
{
    /**
     * @var list<Tool>
     */
    public array $tools;

    /**
     * @param list<Tool> $tools
     */
    public function __construct(array $tools, ?Cursor $nextCursor = null, MetaObject $meta = new MetaObject())
    {
        Assert::that($tools)
            ->isList('ListToolsResult tools must be a list, got non-list array.')
            ->values()->isInstanceOf(Tool::class)
        ;

        $this->tools = $tools;

        parent::__construct($nextCursor, $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('tools', 'ListToolsResult data missing "tools".');
        Assert::that($data['tools'])
            ->isList('ListToolsResult "tools" must be a list, {type} given.')
            ->values()
            ->isArray('ListToolsResult tool entry must be an object, {type} given.')
            ->isMap('ListToolsResult tool entry must be a string-keyed object.')
        ;
        $tools = array_map(Tool::fromArray(...), $data['tools']);

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            $raw = $data['nextCursor'];
            Assert::that($raw)->isString('ListToolsResult "nextCursor" must be a string, {type} given.');
            $nextCursor = new Cursor($raw);
        }

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Result "_meta" must be an object, {type} given.')
                ->isMap('Result "_meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self($tools, $nextCursor, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'tools' => array_map(static fn(Tool $tool): array => $tool->toArray(), $this->tools),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
