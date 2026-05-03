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
     * @todo When the minimum PHP version is bumped to 8.5, final implementers
     *       may declare `: self` instead of `: static` per php/php-src#17724.
     *       Until then, PHP 8.4 strictly enforces `: static` invariance.
     */
    public static function fromArray(array $data): static;

    /**
     * Convert the instance to an array.
     *
     * @return T
     */
    public function toArray(): array;

    /**
     * Implementations substitute `\stdClass` for the empty-object case so
     * `json_encode` emits `{}` rather than `[]`.
     *
     * @return array<string, mixed>|\stdClass
     */
    #[\Override]
    public function jsonSerialize(): array|\stdClass;
}
