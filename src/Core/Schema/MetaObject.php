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
 * Represents the contents of a `_meta` field, which clients and servers use to attach additional metadata to their
 * interactions.
 *
 * Certain key names are reserved by MCP for protocol-level metadata; implementations MUST NOT make assumptions about
 * values at these keys. Additionally, specific schema definitions may reserve particular names for purpose-specific
 * metadata, as declared in those definitions.
 *
 * Valid keys have two segments:
 *
 * **Prefix:**
 * - Optional — if specified, MUST be a series of _labels_ separated by dots (`.`), followed by a slash (`/`).
 * - Labels MUST start with a letter and end with a letter or digit. Interior characters may be letters, digits, or
 *   hyphens (`-`).
 * - Implementations SHOULD use reverse DNS notation (e.g., `com.example/` rather than `example.com/`).
 * - Any prefix where the second label is `modelcontextprotocol` or `mcp` is **reserved** for MCP use. For example:
 *   `io.modelcontextprotocol/`, `dev.mcp/`, `org.modelcontextprotocol.api/`, and `com.mcp.tools/` are all reserved.
 *   However, `com.example.mcp/` is NOT reserved, as the second label is `example`.
 *
 * **Name:**
 * - Unless empty, MUST start and end with an alphanumeric character (`[a-z0-9A-Z]`).
 * - Interior characters may be alphanumeric, hyphens (`-`), underscores (`_`), or dots (`.`).
 *
 * @template-covariant T of array<array-key, mixed> = array<array-key, mixed>
 *
 * @implements Arrayable<T>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#metaobject
 */
abstract readonly class MetaObject implements Arrayable
{
    /**
     * @param array<array-key, mixed> $extras
     */
    public function __construct(public array $extras = [])
    {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[\Override]
    abstract public static function fromArray(array $data): static;

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $out = $this->toArray();
        $map = array_filter($out, is_string(...), \ARRAY_FILTER_USE_KEY);

        return [] !== $out && $map === $out ? $map : (object) $out;
    }
}
