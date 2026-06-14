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

namespace Nexus\Mcp\Core\Schema\NotificationParams;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\MetaObject;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;

/**
 * Parameters for a `notifications/subscriptions/acknowledged` notification.
 *
 * @extends NotificationParams<array{
 *   _meta?: template-type<MetaObject, Arrayable, 'T'>,
 *   notifications: template-type<SubscriptionFilter, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#subscriptionsacknowledgednotificationparams
 */
final readonly class SubscriptionsAcknowledgedNotificationParams extends NotificationParams
{
    public function __construct(public SubscriptionFilter $notifications, MetaObject $meta = new MetaObject())
    {
        parent::__construct(meta: $meta);
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('notifications', 'missing the required "notifications" key.');
        Assert::that($data['notifications'])
            ->isArray('"params.notifications" must be an object, {type} given.')
            ->isMap('"params.notifications" must be a string-keyed object.')
        ;
        $notifications = SubscriptionFilter::fromArray($data['notifications']);

        $meta = new MetaObject();

        if (\array_key_exists('_meta', $data)) {
            Assert::that($data['_meta'])
                ->isArray('"params._meta" must be an object, {type} given.')
                ->isMap('"params._meta" must be a string-keyed object.')
            ;
            $meta = MetaObject::fromArray($data['_meta']);
        }

        return new self(notifications: $notifications, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        $data = [];
        $meta = $this->meta->toArray();

        if ([] !== $meta) {
            $data['_meta'] = $meta;
        }

        $data['notifications'] = $this->notifications->toArray();

        return $data;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->toArray();
        $data['notifications'] = $this->notifications->jsonSerialize();

        return $data;
    }
}
