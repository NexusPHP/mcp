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

namespace Nexus\Mcp\Tests\Client\Auth;

use Nexus\Mcp\Client\Auth\DiscoveredResource;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Auth\ProtectedResourceMetadata;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(DiscoveredResource::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class DiscoveredResourceTest extends AbstractMcpTestCase
{
    public function testItPairsTheResourceDocumentWithItsAuthorizationServer(): void
    {
        $metadata = new ProtectedResourceMetadata(
            new ResourceIdentifier('https://mcp.example.com/mcp'),
            ['https://auth.example.com'],
        );
        $server = new AuthorizationServerMetadata('https://auth.example.com');

        $discovered = new DiscoveredResource($metadata, $server);

        self::assertSame($metadata, $discovered->metadata);
        self::assertSame($server, $discovered->server);
    }
}
