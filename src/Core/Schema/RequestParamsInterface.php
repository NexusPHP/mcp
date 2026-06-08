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
 * Marker for the params object carried by a JSON-RPC request. The heavy
 * `RequestParams` base (client-to-server, with required `_meta`) and the
 * standalone server-to-client params both implement it.
 *
 * @template-covariant T of array<string, mixed>
 */
interface RequestParamsInterface extends \JsonSerializable
{
    /**
     * @return T
     */
    public function toArray(): array;

    /**
     * @return array<string, mixed>|\stdClass
     */
    #[\Override]
    public function jsonSerialize(): array|\stdClass;
}
