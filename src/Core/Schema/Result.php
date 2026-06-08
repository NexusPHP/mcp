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
 * Common result fields.
 *
 * @implements Arrayable<array<string, mixed>>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#result
 */
abstract readonly class Result implements Arrayable
{
    public const string RESULT_TYPE = 'complete';

    public function __construct(public MetaObject $meta = new MetaObject())
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    abstract public static function fromArray(array $data): static;

    /**
     * Serializes the result body. Subclasses override to merge their own fields
     * alongside the `_meta` slice and required `resultType` discriminator returned here.
     */
    #[\Override]
    public function toArray(): array
    {
        $data = [];
        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        $data['resultType'] = static::RESULT_TYPE;

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
