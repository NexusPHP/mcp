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
use Nexus\Mcp\Core\Schema\NotificationParams\CancelledNotificationParams;

/**
 * This notification can be sent by either side to indicate that it is cancelling a previously-issued request.
 *
 * The request SHOULD still be in-flight, but due to communication latency, it is always possible that this
 * notification MAY arrive after the request has already finished.
 *
 * This notification indicates that the result will be unused, so any associated processing SHOULD cease.
 *
 * A client MUST NOT attempt to cancel its `initialize` request.
 *
 * For task cancellation, use the `tasks/cancel` request instead of this notification.
 *
 * @extends JsonRpcNotification<'notifications/cancelled'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#cancellednotification
 */
final readonly class CancelledNotification extends JsonRpcNotification implements ClientNotification, ServerNotification
{
    public function __construct(CancelledNotificationParams $params)
    {
        parent::__construct($params);
    }

    #[\Override]
    public static function method(): string
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

        return new self(CancelledNotificationParams::fromArray($data['params']));
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $params = $this->params->jsonSerialize();

        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method' => static::method(),
            'params' => [] === $params ? new \stdClass() : $params,
        ];
    }
}
