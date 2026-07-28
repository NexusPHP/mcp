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
use Nexus\Mcp\Core\Schema\NotificationParams\ResourceUpdatedNotificationParams;

/**
 * A notification from the server to the client, informing it that a resource has changed and
 * may need to be read again. This is only sent for resources the client opted in to via the
 * `resourceSubscriptions` field of a `subscriptions/listen` request.
 *
 * @property-read ResourceUpdatedNotificationParams $params
 *
 * @extends JsonRpcNotification<'notifications/resources/updated', array{
 *   jsonrpc: '2.0',
 *   method: 'notifications/resources/updated',
 *   params: template-type<ResourceUpdatedNotificationParams, NotificationParams, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#resourceupdatednotification
 */
final readonly class ResourceUpdatedNotification extends JsonRpcNotification implements ServerNotification
{
    public function __construct(ResourceUpdatedNotificationParams $params)
    {
        parent::__construct(params: $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'notifications/resources/updated';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('params', 'missing the required "params" key.');
        Assert::that($data['params'])
            ->isArray('"params" must be an object, {type} given.')
            ->isMap('"params" must be a string-keyed object.')
        ;

        return new self(params: ResourceUpdatedNotificationParams::fromArray($data['params']));
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
