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

namespace Nexus\Mcp\Tests\Fixtures\Client\Time;

use Amp\Cancellation;
use Nexus\Mcp\Client\Time\CancellableDelayInterface;

/**
 * Delay double that records each requested duration and returns immediately.
 */
final class RecordingDelay implements CancellableDelayInterface
{
    /**
     * @var list<float|int>
     */
    public array $sleeps = [];

    #[\Override]
    public function sleep(float|int $seconds, ?Cancellation $cancellation = null): void
    {
        $this->sleeps[] = $seconds;
    }
}
