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
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
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
    public function __construct(array $contents, MetaObject $meta = new MetaObject())
    {
        Assert::that($contents)
            ->isList('"result.contents" must be a list, non-list array given.')
            ->values()->isInstanceOf(ResourceContents::class)
        ;

        $this->contents = $contents;

        parent::__construct($meta);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('contents', '"result" missing the required "contents" key.');
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

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

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
