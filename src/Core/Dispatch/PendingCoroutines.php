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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\Future\awaitAll;

/**
 * Tracks coroutines (`Amp\Future`) spawned during inbound dispatch so a transport
 * `onDrain` listener can await them all before close. Each tracked future
 * removes itself on settle. Counting reports the ones holding a dispatch slot, which is
 * a subset: a drain awaits more than a cap counts.
 *
 * @internal
 */
final class PendingCoroutines implements \Countable
{
    /**
     * @var \SplObjectStorage<Future<mixed>, null>
     */
    private \SplObjectStorage $pending;

    /**
     * The subset of `$pending` holding a slot against the dispatch cap.
     *
     * @var \SplObjectStorage<Future<mixed>, null>
     */
    private \SplObjectStorage $occupied;

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
        $this->pending = new \SplObjectStorage();
        $this->occupied = new \SplObjectStorage();
    }

    /**
     * @param Future<mixed> $future
     * @param bool          $occupiesSlot Whether this coroutine holds a slot against the dispatch cap. Only
     *                                    a request being processed does. A handler awaiting slow I/O still
     *                                    holds one, since the cap exists to shed exactly that pile-up.
     */
    public function track(Future $future, bool $occupiesSlot = true): void
    {
        $this->pending[$future] = null;

        if ($occupiesSlot) {
            $this->occupied[$future] = null;
        }

        $future->finally(function () use ($future): void {
            unset($this->pending[$future], $this->occupied[$future]);
        })->ignore();
    }

    public function flushPending(): void
    {
        while (\count($this->pending) !== 0) {
            [$errors] = awaitAll(iterator_to_array($this->pending));

            foreach ($errors as $error) {
                // Both dispatch paths catch `\Throwable` around their handler, so reaching here means the
                // coroutine failed outside that guard.
                $this->logger->error('A dispatch coroutine ended in an uncaught exception.', ['exception' => $error]);
            }
        }
    }

    /**
     * How many tracked coroutines hold a slot against the dispatch cap. Always at most the number
     * awaited on drain.
     *
     * @return int<0, max>
     */
    #[\Override]
    public function count(): int
    {
        return \count($this->occupied);
    }
}
