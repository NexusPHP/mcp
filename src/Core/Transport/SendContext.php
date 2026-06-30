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

namespace Nexus\Mcp\Core\Transport;

use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Per-call context accompanying an outbound JSON-RPC message on a transport.
 */
final readonly class SendContext
{
    public function __construct(public ?RequestId $relatedRequestId = null)
    {
    }
}
