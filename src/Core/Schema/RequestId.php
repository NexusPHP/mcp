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

namespace Nexus\Mcp\Core\Schema;

use Nexus\Assert\Assert;

/**
 * A uniquely identifying ID for a request in JSON-RPC.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/schema#requestid
 */
final readonly class RequestId
{
    /**
     * @param int|non-empty-string $id
     */
    public function __construct(public int|string $id)
    {
        Assert::that($id)->isIntOrNonEmptyString('"id" must be an int or non-empty string.');
    }
}
