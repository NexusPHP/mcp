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

namespace Nexus\Mcp\Core\Http;

/**
 * One dispatched Server-Sent Events frame: its resolved event type and concatenated data payload.
 *
 * @internal
 */
final readonly class SseFrame
{
    /**
     * @param non-empty-string $event The `event` field, or `message` when the frame declared none
     * @param string           $data  The frame's `data` lines, joined with newlines
     */
    public function __construct(
        public string $event,
        public string $data,
    ) {
    }
}
