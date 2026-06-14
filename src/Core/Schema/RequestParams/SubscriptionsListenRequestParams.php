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

namespace Nexus\Mcp\Core\Schema\RequestParams;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\RequestParams;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;

/**
 * Parameters for a `subscriptions/listen` request.
 *
 * @extends RequestParams<array{
 *   _meta: template-type<RequestMetaObject, Arrayable, 'T'>,
 *   notifications: template-type<SubscriptionFilter, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#subscriptionslistenrequestparams
 */
final readonly class SubscriptionsListenRequestParams extends RequestParams
{
    public function __construct(public SubscriptionFilter $notifications, RequestMetaObject $meta)
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

        Assert::that($data)->hasOffset('_meta', '"params" missing the required "_meta" key.');
        Assert::that($data['_meta'])
            ->isArray('"params._meta" must be an object, {type} given.')
            ->isMap('"params._meta" must be a string-keyed object.')
        ;
        $meta = RequestMetaObject::fromArray($data['_meta']);

        return new self(notifications: $notifications, meta: $meta);
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            '_meta' => $this->meta->toArray(),
            'notifications' => $this->notifications->toArray(),
        ];
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();
        $data['notifications'] = $this->notifications->jsonSerialize();

        return $data;
    }
}
