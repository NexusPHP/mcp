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
 * Records every inbound notification in-memory for assertion in tests.
 *
 * @internal
 *
 * @implements NotificationHandlerInterface<non-empty-string>
 */
final class RecordingNotificationHandler implements NotificationHandlerInterface
{
    /**
     * @var list<JsonRpcNotification<non-empty-string>>
     */
    public private(set) array $notifications = [];

    #[\Override]
    public function handle(JsonRpcNotification $notification): void
    {
        $this->notifications[] = $notification;
    }
}
