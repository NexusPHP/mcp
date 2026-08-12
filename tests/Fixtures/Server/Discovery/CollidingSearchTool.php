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

namespace Nexus\Mcp\Tests\Fixtures\Server\Discovery;

use Nexus\Mcp\Server\Attribute\AsTool;

/**
 * Source object declaring the tool name the duplicate-registration tests collide on.
 */
final class CollidingSearchTool
{
    #[AsTool(name: 'search')]
    public function search(): string
    {
        return 'result';
    }
}
