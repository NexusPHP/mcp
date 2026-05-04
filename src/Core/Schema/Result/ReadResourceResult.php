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
use Nexus\Mcp\Core\Schema\Meta;
use Nexus\Mcp\Core\Schema\ResourceContents;
use Nexus\Mcp\Core\Schema\Result;

/**
 * The server's response to a resources/read request from the client.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#readresourceresult
 */
final readonly class ReadResourceResult extends Result implements ServerResult
{
    /**
     * @var list<ResourceContents>
     */
    public array $contents;

    /**
     * @param list<ResourceContents> $contents
     */
    public function __construct(array $contents, ?Meta $meta = null)
    {
        Assert::that($contents)->isList('ReadResourceResult contents must be a list, got non-list array.');

        foreach ($contents as $entry) {
            Assert::that($entry)->isInstanceOf(ResourceContents::class);
        }

        $this->contents = $contents;

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('contents', 'ReadResourceResult wire data missing "contents".');
        Assert::that($data['contents'])->isArray('ReadResourceResult wire "contents" must be an array, {type} given.');

        $contents = [];

        foreach ($data['contents'] as $entry) {
            Assert::that($entry)
                ->isArray('ReadResourceResult wire contents entry must be an object, {type} given.')
                ->isMap('ReadResourceResult wire contents entry must be a string-keyed object.')
            ;
            $contents[] = ResourceContents::from($entry);
        }

        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Result "_meta" must be an object, {type} given.')
                ->isMap('Result "_meta" must be a string-keyed object.')
            ;
            $meta = Meta::fromArray($data['_meta']);
        }

        return new self($contents, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'contents' => array_map(static fn(ResourceContents $entry): array => $entry->toArray(), $this->contents),
        ]);
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
