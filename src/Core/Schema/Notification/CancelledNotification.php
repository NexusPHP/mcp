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
use Nexus\Mcp\Core\Schema\NotificationParams\CancelledNotificationParams;

/**
 * This notification is sent by the client to indicate that it is cancelling a request it previously issued.
 *
 * On stdio, the server also sends this notification, solely to terminate a `subscriptions/listen` stream: it
 * references the ID of the `subscriptions/listen` request that opened the stream. Servers MUST NOT use this
 * notification to cancel any other request.
 *
 * The request SHOULD still be in-flight, but due to communication latency, it is always possible that this
 * notification MAY arrive after the request has already finished.
 *
 * This notification indicates that the result will be unused, so any associated processing SHOULD cease.
 *
 * @property-read CancelledNotificationParams $params
 *
 * @extends JsonRpcNotification<'notifications/cancelled', array{
 *   jsonrpc: '2.0',
 *   method: 'notifications/cancelled',
 *   params: template-type<CancelledNotificationParams, NotificationParams, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#cancellednotification
 */
final readonly class CancelledNotification extends JsonRpcNotification implements ClientNotification, ServerNotification
{
    public function __construct(CancelledNotificationParams $params)
    {
        parent::__construct(params: $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'notifications/cancelled';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('params', 'missing the required "params" key.');
        Assert::that($data['params'])
            ->isArray('"params" must be an object, {type} given.')
            ->isMap('"params" must be a string-keyed object.')
        ;

        return new self(params: CancelledNotificationParams::fromArray($data['params']));
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
