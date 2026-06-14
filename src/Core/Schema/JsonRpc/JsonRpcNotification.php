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

/**
 * A notification which does not expect a response.
 *
 * @template-covariant TMethod of non-empty-string
 * @template-covariant TEnvelope of array<string, mixed> = array<string, mixed>
 *
 * @extends Notification<TMethod>
 * @implements Arrayable<TEnvelope>
 *
 * @see https://modelcontextprotocol.io/specification/draft/schema#jsonrpcnotification
 */
abstract readonly class JsonRpcNotification extends Notification implements Arrayable, JsonRpcMessage
{
    #[\Override]
    public function jsonSerialize(): array
    {
        $envelope = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method' => static::getMethod(),
        ];

        $params = $this->params->jsonSerialize();

        if ([] !== $params) {
            $envelope['params'] = $params;
        }

        return $envelope;
    }
}
