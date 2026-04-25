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

namespace Nexus\Mcp\Core\Schema\Internal;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Meta;

/**
 * Methods with no typed params use this base directly; methods that add typed
 * fields subclass and override {@see self::fromArray()}.
 *
 * @phpstan-consistent-constructor
 *
 * @implements Arrayable<array{_meta?: array<string, mixed>}>
 */
readonly class NotificationParams implements Arrayable
{
    public function __construct(public ?Meta $meta = null)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $meta = null;

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('Notification params "_meta" must be an object, {type} given.')
                ->isMap('Notification params "_meta" must be a string-keyed object.')
            ;
            $meta = Meta::fromArray($data['_meta']);
        }

        return new static($meta);
    }

    /**
     * Serializes the params body. Subclasses override to merge their own fields
     * alongside the `_meta` slice returned here.
     */
    #[\Override]
    public function toArray(): array
    {
        return null !== $this->meta ? ['_meta' => $this->meta->toArray()] : [];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
