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
 * A progress token, used to associate progress notifications with the original request.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/schema#progresstoken
 */
final readonly class ProgressToken
{
    /**
     * @var int|non-empty-string
     */
    public int|string $token;

    public function __construct(int|string $token)
    {
        if (\is_string($token)) {
            Assert::that($token)->isNonEmptyString('"progressToken" must be a non-empty string.');
        }

        $this->token = $token;
    }
}
