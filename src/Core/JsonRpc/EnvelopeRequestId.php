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
 * Recovers the `id` of a raw JSON-RPC envelope, yielding `null` for anything outside MCP's
 * `int|non-empty-string` narrowing.
 *
 * @internal
 */
final class EnvelopeRequestId
{
    /**
     * @param array<array-key, mixed> $envelope
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
