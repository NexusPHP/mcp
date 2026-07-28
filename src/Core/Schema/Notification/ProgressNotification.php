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
use Nexus\Mcp\Core\Schema\NotificationParams\ProgressNotificationParams;

/**
 * An out-of-band notification used to inform the receiver of a progress update for a long-running request.
 *
 * @property-read ProgressNotificationParams $params
 *
 * @extends JsonRpcNotification<'notifications/progress', array{
 *   jsonrpc: '2.0',
 *   method: 'notifications/progress',
 *   params: template-type<ProgressNotificationParams, NotificationParams, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#progressnotification
 */
final readonly class ProgressNotification extends JsonRpcNotification implements ServerNotification
{
    public function __construct(ProgressNotificationParams $params)
    {
        parent::__construct(params: $params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'notifications/progress';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        Assert::that($data)->hasOffset('params', 'missing the required "params" key.');
        Assert::that($data['params'])
            ->isArray('"params" must be an object, {type} given.')
            ->isMap('"params" must be a string-keyed object.')
        ;

        return new self(params: ProgressNotificationParams::fromArray($data['params']));
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
