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
use Nexus\Mcp\Core\Schema\NotificationParams\ResourceUpdatedNotificationParams;

/**
 * A notification from the server to the client, informing it that a resource has changed and
 * may need to be read again. This should only be sent if the client previously sent a
 * resources/subscribe request.
 *
 * @extends JsonRpcNotification<'notifications/resources/updated'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#resourceupdatednotification
 */
final readonly class ResourceUpdatedNotification extends JsonRpcNotification implements ServerNotification
{
    public function __construct(ResourceUpdatedNotificationParams $params)
    {
        parent::__construct($params);
    }

    #[\Override]
    public static function method(): string
    {
        return 'notifications/resources/updated';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('params', 'ResourceUpdatedNotification data missing "params".');
        Assert::that($data['params'])
            ->isArray('ResourceUpdatedNotification "params" must be an object, {type} given.')
            ->isMap('ResourceUpdatedNotification "params" must be a string-keyed object.')
        ;

        return new self(ResourceUpdatedNotificationParams::fromArray($data['params']));
    }
}
