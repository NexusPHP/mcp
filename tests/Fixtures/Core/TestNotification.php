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

namespace Nexus\Mcp\Tests\Fixtures\Core;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\NotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;

/**
 * @internal
 *
 * @property-read EmptyNotificationParams $params
 *
 * @extends JsonRpcNotification<'tests/test-notification', array{
 *   jsonrpc: '2.0',
 *   method: 'tests/test-notification',
 *   params?: template-type<EmptyNotificationParams, NotificationParams, 'T'>,
 * }>
 */
final readonly class TestNotification extends JsonRpcNotification
{
    public function __construct(EmptyNotificationParams $params = new EmptyNotificationParams())
    {
        parent::__construct($params);
    }

    #[\Override]
    public static function getMethod(): string
    {
        return 'tests/test-notification';
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $params = new EmptyNotificationParams();

        if (\array_key_exists('params', $data)) {
            Assert::that($data['params'])
                ->isArray('TestNotification "params" must be an object, {type} given.')
                ->isMap('TestNotification "params" must be a string-keyed object.')
            ;
            $params = EmptyNotificationParams::fromArray($data['params']);
        }

        return new self($params);
    }

    #[\Override]
    public function toArray(): array
    {
        $envelope = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method' => self::getMethod(),
        ];

        $params = $this->params->toArray();

        if ([] !== $params) {
            $envelope['params'] = $params;
        }

        return $envelope;
    }
}
