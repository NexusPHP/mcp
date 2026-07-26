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

namespace Nexus\Mcp\Tests\Server;

use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Server\ServerInfoDisclosure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ServerInfoDisclosure::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ServerInfoDisclosureTest extends TestCase
{
    public function testFullProjectsTheIdentityUnchanged(): void
    {
        $serverInfo = self::richIdentity();

        self::assertSame($serverInfo, ServerInfoDisclosure::Full->project($serverInfo));
    }

    public function testNameAndVersionDropsEveryDescriptiveField(): void
    {
        $projected = ServerInfoDisclosure::NameAndVersion->project(self::richIdentity());

        self::assertSame(['name' => 'demo', 'version' => '1.0.0'], $projected?->toArray());
    }

    public function testNoneProjectsNothing(): void
    {
        self::assertNull(ServerInfoDisclosure::None->project(self::richIdentity()));
    }

    private static function richIdentity(): Implementation
    {
        return new Implementation(
            name: 'demo',
            version: '1.0.0',
            title: 'Demo Server',
            description: 'A demo.',
            websiteUrl: 'https://example.com',
            icons: [new Icon(src: 'https://example.com/icon.png')],
        );
    }
}
