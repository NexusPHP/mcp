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

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Tracks the inbound JSON-RPC request ids whose handler coroutines are still running, holding the
 * cancellation each one is dispatched under.
 *
 * @internal
 *
 * @see PendingOutboundRequests
 */
final class PendingInboundRequests implements \Countable
{
    /**
     * `DeferredCancellation::__destruct()` cancels, so the map retains the deferred, not the derived token.
     *
     * @var array<non-empty-string, DeferredCancellation>
     */
    private array $map = [];

    /**
     * Claims `$id`, returning the cancellation its handler runs under, or null when it is already in flight.
     */
    public function claim(RequestId $id): ?Cancellation
    {
        $key = self::buildKey($id);

        if (\array_key_exists($key, $this->map)) {
            return null;
        }

        $this->map[$key] = new DeferredCancellation();

        return $this->map[$key]->getCancellation();
    }

    /**
     * Requests cancellation of `$id`, reporting whether a request was in flight under it.
     */
    public function cancel(RequestId $id): bool
    {
        $deferred = $this->map[self::buildKey($id)] ?? null;

        if (null === $deferred) {
            return false;
        }

        $deferred->cancel();

        return true;
    }

    public function release(RequestId $id): void
    {
        unset($this->map[self::buildKey($id)]);
    }

    #[\Override]
    public function count(): int
    {
        return \count($this->map);
    }

    /**
     * @return non-empty-string
     */
    private static function buildKey(RequestId $id): string
    {
        return \sprintf('"id":%s', var_export($id->id, true));
    }
}
