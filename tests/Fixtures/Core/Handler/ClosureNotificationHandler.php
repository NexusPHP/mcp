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

namespace Nexus\Mcp\Tests\Fixtures\Core\Handler;

use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;

/**
 * Closure adapter for `NotificationHandlerInterface`.
 *
 * @internal
 *
 * @implements NotificationHandlerInterface<non-empty-string>
 */
final readonly class ClosureNotificationHandler implements NotificationHandlerInterface
{
    /**
     * @param \Closure(JsonRpcNotification<non-empty-string>): void $handler
     */
    public function __construct(private \Closure $handler)
    {
    }

    #[\Override]
    public function handle(JsonRpcNotification $notification): void
    {
        ($this->handler)($notification);
    }
}
