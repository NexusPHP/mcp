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
 * The `_meta` extension slot carried by notifications and results.
 *
 * @implements Arrayable<array<string, mixed>>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic#meta
 */
final readonly class Meta implements Arrayable
{
    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(public array $extras = [])
    {
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self($data);
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->extras;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
