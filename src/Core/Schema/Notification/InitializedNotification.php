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
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;

/**
 * This notification is sent from the client to the server after initialization has finished.
 *
 * @extends JsonRpcNotification<'notifications/initialized'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#initializednotification
 */
final readonly class InitializedNotification extends JsonRpcNotification implements ClientNotification
{
    #[\Override]
    public static function method(): string
    {
        return 'notifications/initialized';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $params = new EmptyNotificationParams();

        if (\array_key_exists('params', $data)) {
            Assert::that($data['params'])
                ->isArray('InitializedNotification "params" must be an object, {type} given.')
                ->isMap('InitializedNotification "params" must be a string-keyed object.')
            ;
            $params = EmptyNotificationParams::fromArray($data['params']);
        }

        return new self($params);
    }
}
