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

namespace Nexus\Mcp\Core\Schema\Result;

use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * Marker for a client's result to a server-initiated input request, carried as
 * a bare body inside an `InputResponseRequestParams`'s `inputResponses` map.
 *
 * @template-covariant T of array<string, mixed> = array<string, mixed>
 *
 * @extends Arrayable<T>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2026-07-28/schema.ts
 */
interface InputResponse extends Arrayable
{
}
