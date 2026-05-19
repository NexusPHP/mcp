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
 * A notification from the client to the server, informing it that the list of roots has changed.
 * This notification should be sent whenever the client adds, removes, or modifies any root.
 * The server should then request an updated list of roots using the ListRootsRequest.
 *
 * @extends JsonRpcNotification<'notifications/roots/list_changed'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#rootslistchangednotification
 */
final readonly class RootsListChangedNotification extends JsonRpcNotification implements ClientNotification
{
    #[\Override]
    public static function method(): string
    {
        return 'notifications/roots/list_changed';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $params = new EmptyNotificationParams();

        if (\array_key_exists('params', $data)) {
            Assert::that($data['params'])
                ->isArray('"params" must be an object, {type} given.')
                ->isMap('"params" must be a string-keyed object.')
            ;
            $params = EmptyNotificationParams::fromArray($data['params']);
        }

        return new self($params);
    }
}
