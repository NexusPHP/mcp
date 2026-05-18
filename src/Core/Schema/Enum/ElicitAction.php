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

namespace Nexus\Mcp\Core\Schema\Enum;

/**
 * The user action in response to an elicitation request.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.ts
 */
enum ElicitAction: string
{
    /**
     * User submitted the form / confirmed the action.
     */
    case Accept = 'accept';

    /**
     * User explicitly declined the action.
     */
    case Decline = 'decline';

    /**
     * User dismissed without making an explicit choice.
     */
    case Cancel = 'cancel';
}
