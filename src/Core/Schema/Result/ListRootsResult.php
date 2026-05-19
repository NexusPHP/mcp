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
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Root;

/**
 * The client's response to a roots/list request from the server.
 * This result contains an array of Root objects, each representing a root directory or file
 * that the server can operate on.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#listrootsresult
 */
final readonly class ListRootsResult extends Result implements ClientResult
{
    /**
     * @var list<Root>
     */
    public array $roots;

    /**
     * @param list<Root> $roots
     */
    public function __construct(array $roots, MetaObject $meta = new MetaObject())
    {
        Assert::that($roots)
            ->isList('"result.roots" must be a list, non-list array given.')
            ->values()->isInstanceOf(Root::class)
        ;

        $this->roots = $roots;

        parent::__construct($meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('roots', '"result" missing the required "roots" key.');
        Assert::that($data['roots'])
            ->isList('"result.roots" must be a list, {type} given.')
            ->values()
            ->isArray('each "result.root" must be an object, {type} given.')
            ->isMap('each "result.root" must be a string-keyed object.')
        ;
        $roots = array_map(Root::fromArray(...), $data['roots']);

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"result._meta" must be an object, {type} given.')
                ->isMap('"result._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self($roots, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'roots' => array_map(static fn(Root $root): array => $root->toArray(), $this->roots),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
