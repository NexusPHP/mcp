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

namespace Nexus\Mcp\Core\JsonRpc;

use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Recovers the `id` of a raw JSON-RPC envelope, so a malformed message can still be answered to the request
 * that sent it. MCP narrows the JSON-RPC id to `int|non-empty-string`, so anything else yields `null` and is
 * answered id-less.
 *
 * @internal
 */
final class EnvelopeRequestId
{
    /**
     * @param array<string, mixed> $envelope
     */
    public static function recover(array $envelope): ?RequestId
    {
        $id = $envelope['id'] ?? null;

        if (! \is_int($id) && (! \is_string($id) || '' === $id)) {
            return null;
        }

        return new RequestId(id: $id);
    }
}
