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

/**
 * Common params for any notification.
 *
 * @implements Arrayable<array{_meta?: template-type<MetaObject, Arrayable, 'T'>, ...}>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
abstract readonly class NotificationParams implements Arrayable
{
    public function __construct(public ?MetaObject $meta = null)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    abstract public static function fromArray(array $data): static;

    /**
     * Serializes the params body. Subclasses override to merge their own fields
     * alongside the `_meta` slice returned here.
     */
    #[\Override]
    public function toArray(): array
    {
        if (null === $this->meta) {
            return [];
        }

        $meta = $this->meta->toArray();

        return [] === $meta ? [] : ['_meta' => $meta];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
