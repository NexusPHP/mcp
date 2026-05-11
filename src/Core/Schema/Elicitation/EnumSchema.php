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
 * Marker for the `EnumSchema` union of enum-flavored `PrimitiveSchemaDefinition`
 * variants. Members are `UntitledSingleSelectEnumSchema`,
 * `TitledSingleSelectEnumSchema`, `UntitledMultiSelectEnumSchema`,
 * `TitledMultiSelectEnumSchema`, and `LegacyTitledEnumSchema`.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#enumschema
 */
interface EnumSchema extends PrimitiveSchemaDefinition
{
}
