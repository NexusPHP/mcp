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

namespace Nexus\Mcp\Tests\Fixtures\Core\Transport;

/**
 * Ordered record of the inbound JSON-RPC envelopes a transport emitted to its message listeners.
 *
 * @internal
 */
final class EnvelopeLog
{
    /**
     * @var list<array<string, mixed>>
     */
    public private(set) array $envelopes = [];

    /**
     * @param array<string, mixed> $envelope
     */
    public function record(array $envelope): void
    {
        $this->envelopes[] = $envelope;
    }
}
