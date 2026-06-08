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
 * JSONRPCNotification of a log message passed from server to client. The client opts in by
 * setting `"io.modelcontextprotocol/logLevel"` in a request's `_meta`.
 *
 * @property-read LoggingMessageNotificationParams $params
 *
 * @extends JsonRpcNotification<'notifications/message'>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#loggingmessagenotification
 */
final readonly class LoggingMessageNotification extends JsonRpcNotification implements ServerNotification
{
    public function __construct(LoggingMessageNotificationParams $params)
    {
        parent::__construct($params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'notifications/message';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('params', 'missing the required "params" key.');
        Assert::that($data['params'])
            ->isArray('"params" must be an object, {type} given.')
            ->isMap('"params" must be a string-keyed object.')
        ;

        return new self(LoggingMessageNotificationParams::fromArray($data['params']));
    }
}
