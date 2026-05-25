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

use Amp\Future;

use function Amp\Future\awaitAll;

/**
 * Tracks coroutines (`Amp\Future`) spawned during inbound dispatch so a transport
 * `onDrain` listener can await them all before close. Each tracked future
 * removes itself on settle.
 *
 * @internal
 */
final class PendingCoroutines implements \Countable
{
    /**
     * @var \SplObjectStorage<Future<mixed>, null>
     */
    private \SplObjectStorage $pending;

    public function __construct()
    {
        $this->pending = new \SplObjectStorage();
    }

    /**
     * @param Future<mixed> $future
     */
    public function track(Future $future): void
    {
        $this->pending[$future] = null;

        $future->finally(function () use ($future): void {
            unset($this->pending[$future]);
        })->ignore();
    }

    public function flushPending(): void
    {
        while (\count($this->pending) !== 0) {
            awaitAll(iterator_to_array($this->pending));
        }
    }

    /**
     * @return int<0, max>
     */
    #[\Override]
    public function count(): int
    {
        return \count($this->pending);
    }
}
