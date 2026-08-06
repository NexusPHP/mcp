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

namespace Nexus\Mcp\Tests\Extension\Apps\Client;

use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Extension\Apps\Client\AppTool;
use Nexus\Mcp\Extension\Apps\Schema\UiToolMeta;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(AppTool::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class AppToolTest extends AbstractMcpTestCase
{
    public function testPairsTheToolWithItsMeta(): void
    {
        $tool = new Tool(name: 'demo', inputSchema: ['type' => 'object']);
        $uiMeta = new UiToolMeta(resourceUri: 'ui://demo/panel');

        $appTool = new AppTool(tool: $tool, uiMeta: $uiMeta);

        self::assertSame($tool, $appTool->tool);
        self::assertSame($uiMeta, $appTool->uiMeta);
    }
}
