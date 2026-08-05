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

namespace Nexus\Mcp\Extension\Apps\Client;

use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Extension\Apps\Schema\UiToolMeta;

/**
 * A tool paired with its resolved `_meta.ui` metadata.
 */
final readonly class AppTool
{
    public function __construct(public Tool $tool, public UiToolMeta $uiMeta)
    {
    }
}
