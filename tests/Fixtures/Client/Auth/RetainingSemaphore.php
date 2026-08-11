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

namespace Nexus\Mcp\Tests\Fixtures\Client\Auth;

use Amp\DeferredFuture;
use Amp\Sync\Lock;
use Amp\Sync\Semaphore;

/**
 * Single-permit `Semaphore` whose locks release only explicitly.
 *
 * @internal
 */
final class RetainingSemaphore implements Semaphore
{
    public int $released = 0;

    /**
     * Every lock minted, held so a dropped one is never destructor-released.
     *
     * @var list<Lock>
     */
    public array $minted = [];

    private bool $held = false;

    /**
     * @var list<DeferredFuture<Lock>>
     */
    private array $queue = [];

    #[\Override]
    public function acquire(): Lock
    {
        if (! $this->held) {
            $this->held = true;

            return $this->mint();
        }

        /** @var DeferredFuture<Lock> $deferred */
        $deferred = new DeferredFuture();
        $this->queue[] = $deferred;

        return $deferred->getFuture()->await();
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    private function mint(): Lock
    {
        $lock = new Lock(function (): void {
            ++$this->released;
            $next = array_shift($this->queue);

            if (null === $next) {
                $this->held = false;

                return;
            }

            $next->complete($this->mint());
        });

        $this->minted[] = $lock;

        return $lock;
    }
}
