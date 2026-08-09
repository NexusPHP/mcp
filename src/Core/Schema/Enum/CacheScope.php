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
 * Indicates the intended scope of the cached response, analogous to HTTP
 * `Cache-Control: public` vs `Cache-Control: private`.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2026-07-28/schema.ts
 */
enum CacheScope: string
{
    /**
     * Any client or intermediary (e.g., shared gateway, proxy) MAY cache the response and serve it to any user.
     */
    case Public = 'public';

    /**
     * Only the requesting user's client MAY cache the response, and a shared cache MUST NOT serve a copy to
     * a different user.
     */
    case Private = 'private';
}
