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
use Nexus\Mcp\Core\Schema\Tool;

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
    public function __construct(array $tools, ?Cursor $nextCursor = null, ?Meta $meta = null)
    {
        Assert::that($tools)->isList('ListToolsResult tools must be a list, got non-list array.');

        foreach ($tools as $tool) {
            Assert::that($tool)->isInstanceOf(Tool::class);
        }

        $this->tools = $tools;

        parent::__construct($nextCursor, $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('tools', 'ListToolsResult wire data missing "tools".');
        Assert::that($data['tools'])->isArray('ListToolsResult wire "tools" must be an array, {type} given.');

        $tools = [];

        foreach ($data['tools'] as $entry) {
            Assert::that($entry)
                ->isArray('ListToolsResult wire tool entry must be an object, {type} given.')
                ->isMap('ListToolsResult wire tool entry must be a string-keyed object.')
            ;
            $tools[] = Tool::fromArray($entry);
        }

        $nextCursor = null;

        if (\array_key_exists('nextCursor', $data)) {
            $raw = $data['nextCursor'];
            Assert::that($raw)->isString('ListToolsResult wire "nextCursor" must be a string, {type} given.');
            $nextCursor = new Cursor($raw);
        }

        $meta = Meta::parseFromWire($data, 'Result');

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
