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

namespace Nexus\Mcp\Core\Schema\Notification;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\TaskStatusNotificationParams;

/**
 * An optional notification from the receiver to the requestor, informing them
 * that a task's status has changed. Receivers are not required to send these
 * notifications.
 *
 * @extends JsonRpcNotification<'notifications/tasks/status'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#taskstatusnotification
 */
final readonly class TaskStatusNotification extends JsonRpcNotification implements ClientNotification, ServerNotification
{
    public function __construct(TaskStatusNotificationParams $params)
    {
        parent::__construct($params);
    }

    #[\Override]
    public static function method(): string
    {
        return 'notifications/tasks/status';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('params', 'missing the required "params" key.');
        Assert::that($data['params'])
            ->isArray('"params" must be an object, {type} given.')
            ->isMap('"params" must be a string-keyed object.')
        ;

        return new self(TaskStatusNotificationParams::fromArray($data['params']));
    }
}
