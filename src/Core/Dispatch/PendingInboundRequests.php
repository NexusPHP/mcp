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

namespace Nexus\Mcp\Core\Dispatch;

use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Tracks the set of inbound JSON-RPC request ids whose handler coroutines are still running.
 *
 * @internal
 *
 * @see PendingOutboundRequests
 */
final class PendingInboundRequests implements \Countable
{
    /**
     * @var array<non-empty-string, true>
     */
    private array $map = [];

    public function claim(RequestId $id): bool
    {
        $key = self::key($id);

        if (\array_key_exists($key, $this->map)) {
            return false;
        }

        $this->map[$key] = true;

        return true;
    }

    public function release(RequestId $id): void
    {
        unset($this->map[self::key($id)]);
    }

    #[\Override]
    public function count(): int
    {
        return \count($this->map);
    }

    /**
     * @return non-empty-string
     */
    private static function key(RequestId $id): string
    {
        return \sprintf('"id":%s', var_export($id->id, true));
    }
}
