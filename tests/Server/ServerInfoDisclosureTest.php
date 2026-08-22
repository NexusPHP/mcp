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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ServerInfoDisclosure::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ServerInfoDisclosureTest extends AbstractMcpTestCase
{
    public function testFullProjectsTheIdentityUnchanged(): void
    {
        $serverInfo = $this->richIdentity();

        self::assertSame($serverInfo, ServerInfoDisclosure::Full->project($serverInfo));
    }

    public function testNameAndVersionDropsEveryDescriptiveField(): void
    {
        $projected = ServerInfoDisclosure::NameAndVersion->project($this->richIdentity());

        self::assertSame(['name' => 'demo', 'version' => '1.0.0'], $projected?->toArray());
    }

    public function testNoneProjectsNothing(): void
    {
        self::assertNull(ServerInfoDisclosure::None->project($this->richIdentity()));
    }

    private function richIdentity(): Implementation
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
