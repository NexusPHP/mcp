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

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\Result;

/**
 * A result that supports a time-to-live (TTL) hint for client-side caching.
 *
 * @template-covariant T of array<string, mixed>
 *
 * @extends Result<T>
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/draft/schema.ts
 */
abstract readonly class CacheableResult extends Result
{
    public function __construct(
        public int $ttlMs,
        public CacheScope $cacheScope,
        MetaObject $meta = new MetaObject(),
    ) {
        Assert::that($this->ttlMs)->isNaturalInt('"result.ttlMs" must be a non-negative integer, {value} given.');

        parent::__construct(meta: $meta);
    }
}
