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

namespace Nexus\Mcp\Tests\Extension\Tasks\Client;

use Nexus\Mcp\Extension\Tasks\Client\TasksClientExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TasksClientExtension::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class TasksClientExtensionTest extends TestCase
{
    public function testDeclaresTheOfficialIdentifierWithEmptySettings(): void
    {
        $extension = new TasksClientExtension();

        self::assertSame('io.modelcontextprotocol/tasks', $extension->getIdentifier());
        self::assertSame([], $extension->getSettings());
        self::assertSame([], $extension->getRequests());
        self::assertSame([], $extension->getNotifications());
        self::assertSame([], $extension->getRequestHandlers());
        self::assertSame([], $extension->getNotificationHandlers());
    }

    public function testDeclaresTheThreeOutboundMethods(): void
    {
        self::assertSame(
            ['tasks/get', 'tasks/update', 'tasks/cancel'],
            new TasksClientExtension()->getOutboundRequests(),
        );
    }
}
