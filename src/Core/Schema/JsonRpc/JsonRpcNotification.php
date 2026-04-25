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

use Nexus\Mcp\Core\Schema\Internal\Notification;

/**
 * A notification which does not expect a response.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic#notifications
 *
 * @template-covariant TMethod of non-empty-string
 *
 * @extends Notification<TMethod>
 */
abstract readonly class JsonRpcNotification extends Notification implements JsonRpcMessage
{
    /**
     * @return array{
     *   jsonrpc: '2.0',
     *   method: non-empty-string,
     *   params?: array<string, mixed>,
     * }
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            ...parent::toArray(),
        ];
    }
}
