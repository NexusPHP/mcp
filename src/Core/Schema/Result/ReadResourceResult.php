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
use Nexus\Mcp\Core\Schema\ResourceContents\BlobResourceContents;
use Nexus\Mcp\Core\Schema\ResourceContents\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result;

/**
 * The server's response to a resources/read request from the client.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#readresourceresult
 */
final readonly class ReadResourceResult extends Result implements ServerResult
{
    /**
     * @var list<BlobResourceContents|TextResourceContents>
     */
    public array $contents;

    /**
     * @param list<BlobResourceContents|TextResourceContents> $contents
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

        $meta = Meta::parseFromWire($data, 'Result');

        return new self($contents, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'contents' => array_map(static fn(ResourceContents $entry): array => $entry->toArray(), $this->contents),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
