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
 * The sender or recipient of messages and data in a conversation.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#role
 */
enum Role: string
{
    /**
     * Human author of the message.
     */
    case User = 'user';

    /**
     * Model author of the message.
     */
    case Assistant = 'assistant';
}
