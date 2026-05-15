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

namespace Nexus\Mcp\Core\Schema\JsonRpc;

use Nexus\Mcp\Core\Schema\Arrayable;
use Nexus\Mcp\Core\Schema\Notification;
use Nexus\Mcp\Core\Schema\NotificationParams;

/**
 * A notification which does not expect a response.
 *
 * @template-covariant TMethod of non-empty-string
 *
 * @extends Notification<TMethod>
 * @implements Arrayable<array{
 *   jsonrpc: '2.0',
 *   method: non-empty-string,
 *   params?: template-type<NotificationParams, Arrayable, 'T'>,
 * }>
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#jsonrpcnotification
 */
abstract readonly class JsonRpcNotification extends Notification implements Arrayable, JsonRpcMessage
{
    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    abstract public static function fromArray(array $data): static;

    #[\Override]
    public function toArray(): array
    {
        $envelope = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method' => static::method(),
        ];

        $params = $this->params->toArray();

        if ([] !== $params) {
            $envelope['params'] = $params;
        }

        return $envelope;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $envelope = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method' => static::method(),
        ];

        $params = $this->params->jsonSerialize();

        if ([] !== $params) {
            $envelope['params'] = $params;
        }

        return $envelope;
    }
}
