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
 */
final readonly class RequestId
{
    /**
     * @var int|non-empty-string
     */
    public int|string $id;

    public function __construct(int|string $id)
    {
        if (\is_string($id)) {
            Assert::that($id)->isNonEmptyString('Request ID must be a non-empty string.');
        }

        $this->id = $id;
    }
}
