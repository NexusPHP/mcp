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

namespace Nexus\Mcp\Tests\Extension\Auth\ClientCredentials;

use Nexus\Mcp\Extension\Auth\ClientCredentials\ClientCredentialsServerExtension;
use Nexus\Mcp\Server\ServerBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClientCredentialsServerExtension::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ClientCredentialsServerExtensionTest extends TestCase
{
    public function testDeclaresTheOfficialIdentifierWithEmptySettings(): void
    {
        $extension = new ClientCredentialsServerExtension();

        self::assertSame('io.modelcontextprotocol/oauth-client-credentials', $extension->getIdentifier());
        self::assertSame([], $extension->getSettings());
        self::assertSame([], $extension->getRequests());
        self::assertSame([], $extension->getNotifications());
        self::assertSame([], $extension->getRequestHandlers());
        self::assertSame([], $extension->getNotificationHandlers());
    }

    public function testBuildsWithNoMethodsOfItsOwn(): void
    {
        $this->expectNotToPerformAssertions();

        new ServerBuilder()
            ->setServerInfo('demo', '1.0.0')
            ->enableExtension(new ClientCredentialsServerExtension())
            ->build()
        ;
    }
}
