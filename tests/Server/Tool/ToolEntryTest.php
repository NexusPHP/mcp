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

namespace Nexus\Mcp\Tests\Server\Tool;

use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolEntry::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ToolEntryTest extends TestCase
{
    public function testExposesToolAndExecutor(): void
    {
        $tool = new Tool('echo', ['type' => 'object']);
        $executor = new ClosureToolExecutor(
            static fn(): CallToolResult => new CallToolResult([]),
        );

        $entry = new ToolEntry($tool, $executor);

        self::assertSame($tool, $entry->tool);
        self::assertSame($executor, $entry->executor);
    }
}
