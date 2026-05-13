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
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#root
 */
final readonly class Root implements Arrayable
{
    public function __construct(
        public string $uri,
        public ?string $name = null,
        public ?MetaObject $meta = null,
    ) {
        Assert::that($uri)->startsWith('file://', 'Root URI must start with {needle}, got {value}.');
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('uri', 'Root data missing "uri".');
        $uri = $data['uri'];
        Assert::that($uri)->isString('Root "uri" must be a string, {type} given.');

        $name = $data['name'] ?? null;
        Assert::that($name)->nullOr()->isString('Root "name" must be a string or null, {type} given.');

        $meta = MetaObject::parseFrom($data, 'Root');

        return new self($uri, $name, $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = ['uri' => $this->uri];

        if (null !== $this->name) {
            $data['name'] = $this->name;
        }

        if (null !== $this->meta) {
            $meta = $this->meta->toArray();

            if ([] !== $meta) {
                $data['_meta'] = $meta;
            }
        }

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
