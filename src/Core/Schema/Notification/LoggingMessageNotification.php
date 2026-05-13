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
use Nexus\Mcp\Core\Schema\NotificationParams\LoggingMessageNotificationParams;

/**
 * JSONRPCNotification of a log message passed from server to client. If no logging/setLevel request has been
 * sent from the client, the server MAY decide which messages to send automatically.
 *
 * @extends JsonRpcNotification<'notifications/message'>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#loggingmessagenotification
 */
final readonly class LoggingMessageNotification extends JsonRpcNotification implements ServerNotification
{
    public function __construct(LoggingMessageNotificationParams $params)
    {
        parent::__construct($params);
    }

    #[\Override]
    public static function method(): string
    {
        return 'notifications/message';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('params', 'LoggingMessageNotification data missing "params".');
        Assert::that($data['params'])
            ->isArray('LoggingMessageNotification "params" must be an object, {type} given.')
            ->isMap('LoggingMessageNotification "params" must be a string-keyed object.')
        ;

        return new self(LoggingMessageNotificationParams::fromArray($data['params']));
    }
}
