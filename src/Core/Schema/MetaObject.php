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
 * The `_meta` extension slot carried by notifications and results.
 *
 * @implements Arrayable<array<string, mixed>>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic#_meta
 */
final readonly class MetaObject implements Arrayable
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

    /**
     * Reads the optional `_meta` slot from a parent payload, validating its
     * shape. Returns an empty instance when the key is absent. The `$context`
     * prefix scopes the error message to the calling shape (e.g. `"Result"`,
     * `"Notification params"`).
     *
     * @param array<string, mixed> $data
     * @param non-empty-string     $context
     */
    public static function parseFrom(array $data, string $context): self
    {
        if (! \array_key_exists('_meta', $data)) {
            return new self();
        }

        Assert::that($data['_meta'])
            ->isArray(\sprintf('%s "_meta" must be an object, {type} given.', $context))
            ->isMap(\sprintf('%s "_meta" must be a string-keyed object.', $context))
        ;

        return self::fromArray($data['_meta']);
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->extras;
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        return [] === $this->extras ? new \stdClass() : $this->extras;
    }
}
