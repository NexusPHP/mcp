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

namespace Nexus\Mcp\Core\Schema\Elicitation;

use Nexus\Mcp\Core\Schema\Arrayable;

/**
 * Restricted schema definitions that only allow primitive types
 * without nested objects or arrays.
 *
 * @template-covariant T of array<string, mixed> = array<string, mixed>
 *
 * @extends Arrayable<T>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#primitiveschemadefinition
 */
interface PrimitiveSchemaDefinition extends Arrayable
{
}
