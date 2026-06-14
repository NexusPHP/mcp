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

/**
 * Marker for the `SingleSelectEnumSchema` union: `UntitledSingleSelectEnumSchema`
 * and `TitledSingleSelectEnumSchema`.
 *
 * @template-covariant T of array<string, mixed> = array<string, mixed>
 *
 * @extends EnumSchema<T>
 *
 * @phpstan-sealed UntitledSingleSelectEnumSchema|TitledSingleSelectEnumSchema
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#singleselectenumschema
 */
interface SingleSelectEnumSchema extends EnumSchema
{
}
