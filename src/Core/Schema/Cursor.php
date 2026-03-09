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
 * An opaque token used to represent a cursor for pagination.
 */
final readonly class Cursor
{
    /**
     * @var non-empty-string
     */
    public string $cursor;

    public function __construct(string $cursor)
    {
        Assert::that($cursor)->isNonEmptyString('Cursor must be a non-empty string.');

        $this->cursor = $cursor;
    }
}
