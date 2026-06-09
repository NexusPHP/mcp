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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;

/**
 * Represents a root directory or file that the server can operate on.
 *
 * @implements Arrayable<array{
 *   uri: string,
 *   name?: string,
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#root
 */
final readonly class Root implements Arrayable
{
    public function __construct(
        public string $uri,
        public ?string $name = null,
        public MetaObject $meta = new MetaObject(),
    ) {
        Assert::that($uri)->startsWith('file://', 'root "uri" must start with {needle}, got {value}.');
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'root missing the required "uri" key.');
        $uri = $data['uri'];
        Assert::that($uri)->isString('root "uri" must be a string, {type} given.');

        $name = $data['name'] ?? null;
        Assert::that($name)->nullOr()->isString('root "name" must be a string or null, {type} given.');

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('root "_meta" must be an object, {type} given.')
                ->isMap('root "_meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self(uri: $uri, name: $name, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['uri' => $this->uri];

        if (null !== $this->name) {
            $data['name'] = $this->name;
        }

        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
