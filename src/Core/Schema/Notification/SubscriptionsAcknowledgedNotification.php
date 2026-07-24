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
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\SubscriptionsAcknowledgedNotificationParams;

/**
 * Sent by the server to acknowledge that a `subscriptions/listen` subscription has been
 * established and to report which notification types it agreed to honor. This notification
 * MUST be the first message the server sends carrying the subscription's ID in
 * `io.modelcontextprotocol/subscriptionId`. The server MUST NOT send any notification on the
 * subscription before acknowledging it. On stdio, where every subscription shares one channel,
 * this ordering is defined per subscription ID and not per channel: messages belonging to other
 * subscriptions MAY be interleaved before it.
 *
 * @property-read SubscriptionsAcknowledgedNotificationParams $params
 *
 * @extends JsonRpcNotification<'notifications/subscriptions/acknowledged', array{
 *   jsonrpc: '2.0',
 *   method: 'notifications/subscriptions/acknowledged',
 *   params: template-type<SubscriptionsAcknowledgedNotificationParams, NotificationParams, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#subscriptionsacknowledgednotification
 */
final readonly class SubscriptionsAcknowledgedNotification extends JsonRpcNotification implements ServerNotification
{
    public function __construct(SubscriptionsAcknowledgedNotificationParams $params)
    {
        parent::__construct(params: $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'notifications/subscriptions/acknowledged';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('params', 'missing the required "params" key.');
        Assert::that($data['params'])
            ->isArray('"params" must be an object, {type} given.')
            ->isMap('"params" must be a string-keyed object.')
        ;

        return new self(params: SubscriptionsAcknowledgedNotificationParams::fromArray($data['params']));
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method' => static::getMethod(),
            'params' => $this->params->toArray(),
        ];
    }
}
