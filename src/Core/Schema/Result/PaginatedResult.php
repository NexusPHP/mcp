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

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\MetaObject;

/**
 * Common shape for results that paginate via an opaque cursor. Subclasses add their own
 * payload field alongside the optional `nextCursor`.
 *
 * @template-covariant T of array<string, mixed>
 *
 * @extends CacheableResult<T>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/draft/schema.ts
 */
abstract readonly class PaginatedResult extends CacheableResult
{
    public function __construct(
        int $ttlMs,
        CacheScope $cacheScope,
        public ?Cursor $nextCursor = null,
        MetaObject $meta = new MetaObject(),
    ) {
        parent::__construct(ttlMs: $ttlMs, cacheScope: $cacheScope, meta: $meta);
    }
}
