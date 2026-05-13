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

namespace Nexus\Mcp\Core\Schema\Task;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * Metadata for augmenting a request with task execution.
 *
 * Include this in the `task` field of the request parameters.
 *
 * @implements Arrayable<array{ttl?: int}>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#taskmetadata
 */
final readonly class TaskMetadata implements Arrayable
{
    public function __construct(public ?int $ttl = null)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $ttl = $data['ttl'] ?? null;
        Assert::that($ttl)->nullOr()->isInt('TaskMetadata "ttl" must be an int or null, {type} given.');

        return new self($ttl);
    }

    /**
     * Reads the optional `task` slot from a parent payload, validating
     * its shape. Returns `null` when the key is absent. The `$context` prefix
     * scopes the error message to the calling shape (e.g. `"CallToolRequestParams"`).
     *
     * @param array<string, mixed> $data
     * @param non-empty-string     $context
     */
    public static function parseFrom(array $data, string $context): ?self
    {
        if (! \array_key_exists('task', $data)) {
            return null;
        }

        Assert::that($data['task'])
            ->isArray(\sprintf('%s "task" must be an object, {type} given.', $context))
            ->isMap(\sprintf('%s "task" must be a string-keyed object.', $context))
        ;

        return self::fromArray($data['task']);
    }

    #[\Override]
    public function toArray(): array
    {
        if (null === $this->ttl) {
            return [];
        }

        return ['ttl' => $this->ttl];
    }

    #[\Override]
    public function jsonSerialize(): array|\stdClass
    {
        $data = $this->toArray();

        return [] === $data ? new \stdClass() : $data;
    }
}
