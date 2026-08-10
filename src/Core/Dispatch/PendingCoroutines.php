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
 * Tracker for the `Amp\Future` instances inbound dispatch holds against a budget.
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
     * @param bool          $occupiesSlot Whether this future holds a slot against the budget
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
                $this->logger->error('A dispatch coroutine ended in an uncaught exception.', ['exception' => $error]);
            }
        }
    }

    #[\Override]
    public function count(): int
    {
        return \count($this->occupied);
    }
}
