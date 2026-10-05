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

namespace Nexus\Mcp\Tests\Extension\Skills\Client;

use Nexus\Mcp\Extension\Skills\Client\SkillsClientExtension;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SkillsClientExtension::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class SkillsClientExtensionTest extends AbstractMcpTestCase
{
    public function testDeclaresTheOfficialIdentifierWithEmptySettings(): void
    {
        $extension = new SkillsClientExtension();

        self::assertSame('io.modelcontextprotocol/skills', $extension->getIdentifier());
        self::assertSame([], $extension->getSettings());
        self::assertSame([], $extension->getRequests());
        self::assertSame([], $extension->getNotifications());
        self::assertSame([], $extension->getRequestHandlers());
        self::assertSame([], $extension->getNotificationHandlers());
    }

    public function testDeclaresTheThreeOutboundMethods(): void
    {
        self::assertSame(
            ['skills/list', 'skills/get', 'resources/directory/read'],
            (new SkillsClientExtension())->getOutboundRequests(),
        );
    }
}
