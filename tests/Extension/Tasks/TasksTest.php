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

namespace Nexus\Mcp\Tests\Extension\Tasks;

use Nexus\Mcp\Extension\Tasks\Tasks;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(Tasks::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class TasksTest extends AbstractMcpTestCase
{
    public function testPinsTheProtocolVocabulary(): void
    {
        self::assertSame(
            ['IDENTIFIER' => 'io.modelcontextprotocol/tasks'],
            (new \ReflectionClass(Tasks::class))->getConstants(),
        );
    }
}
