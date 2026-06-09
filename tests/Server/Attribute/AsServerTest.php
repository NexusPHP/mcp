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

namespace Nexus\Mcp\Tests\Server\Attribute;

use Nexus\Mcp\Core\Schema\Icon;
use Nexus\Mcp\Server\Attribute\AsServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AsServer::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class AsServerTest extends TestCase
{
    public function testDefaultsOptionalFieldsToNull(): void
    {
        $server = new AsServer('demo', '1.0.0');

        self::assertSame('demo', $server->name);
        self::assertSame('1.0.0', $server->version);
        self::assertNull($server->title);
        self::assertNull($server->description);
        self::assertNull($server->websiteUrl);
        self::assertNull($server->instructions);
        self::assertNull($server->icons);
    }

    public function testStoresAllValues(): void
    {
        $icon = new Icon(src: 'https://example.test/icon.svg');

        $server = new AsServer(
            name: 'demo',
            version: '2.3.4',
            title: 'Demo Server',
            description: 'A demo.',
            websiteUrl: 'https://example.test',
            instructions: 'Be helpful.',
            icons: [$icon],
        );

        self::assertSame('demo', $server->name);
        self::assertSame('2.3.4', $server->version);
        self::assertSame('Demo Server', $server->title);
        self::assertSame('A demo.', $server->description);
        self::assertSame('https://example.test', $server->websiteUrl);
        self::assertSame('Be helpful.', $server->instructions);
        self::assertSame([$icon], $server->icons);
    }
}
