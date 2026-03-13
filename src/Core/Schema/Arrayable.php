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
 * Interface for classes that can be converted to and from arrays.
 *
 * @template T of array<string, mixed>
 */
interface Arrayable extends \JsonSerializable
{
    /**
     * Create an instance of the class from an array.
     *
     * @param T $data
     *
     * @return self<T>
     */
    public static function fromArray(array $data): self;

    /**
     * Convert the instance to an array.
     *
     * @return T
     */
    public function toArray(): array;

    /**
     * @return T
     */
    #[\Override]
    public function jsonSerialize(): array;
}
