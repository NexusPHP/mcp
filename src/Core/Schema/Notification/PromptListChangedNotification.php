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
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;

/**
 * An optional notification from the server to the client, informing it that the list of prompts
 * it offers has changed. This is only delivered on a `subscriptions/listen` stream when the
 * client requested it via the `promptsListChanged` filter field.
 *
 * @property-read EmptyNotificationParams $params
 *
 * @extends JsonRpcNotification<'notifications/prompts/list_changed', array{
 *   jsonrpc: '2.0',
 *   method: 'notifications/prompts/list_changed',
 *   params?: template-type<EmptyNotificationParams, NotificationParams, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#promptlistchangednotification
 */
final readonly class PromptListChangedNotification extends JsonRpcNotification implements ServerNotification
{
    #[\Override]
    public static function getMethod(): string
    {
        return 'notifications/prompts/list_changed';
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

        return new self(params: $params);
    }

    #[\Override]
    public function toArray(): array
    {
        $envelope = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method' => static::getMethod(),
        ];

        $params = $this->params->toArray();

        if ([] !== $params) {
            $envelope['params'] = $params;
        }

        return $envelope;
    }
}
