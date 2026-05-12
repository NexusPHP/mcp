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

namespace Nexus\Mcp\Core\Transport;

/**
 * @internal
 */
final class Subscription implements SubscriptionInterface
{
    private bool $disposed = false;

    /**
     * @param \Closure(): void $onDispose
     */
    public function __construct(private readonly \Closure $onDispose)
    {
    }

    #[\Override]
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        ($this->onDispose)();
    }
}
