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

namespace Nexus\Mcp\Tests\Client\Handler\Notification;

use Nexus\Mcp\Client\Dispatch\DiscoveredServerCapabilities;
use Nexus\Mcp\Client\Handler\Notification\ExtensionGateNotificationHandler;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\TestNotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

/**
 * @internal
 */
#[CoversClass(ExtensionGateNotificationHandler::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ExtensionGateNotificationHandlerTest extends AbstractMcpTestCase
{
    public function testDeliversBeforeDiscovery(): void
    {
        $delivered = 0;
        $handler = $this->buildGate(new DiscoveredServerCapabilities(), $delivered);

        $handler->handle(new TestNotification());

        self::assertSame(1, $delivered);
    }

    public function testDeliversWhenTheServerAdvertisedTheExtension(): void
    {
        $delivered = 0;
        $discovered = new DiscoveredServerCapabilities();
        $discovered->record(new ServerCapabilities(extensions: ['com.example/feature' => []]));
        $handler = $this->buildGate($discovered, $delivered);

        $handler->handle(new TestNotification());

        self::assertSame(1, $delivered);
    }

    public function testDropsAndLogsWhenTheServerDidNotAdvertiseTheExtension(): void
    {
        $delivered = 0;
        $discovered = new DiscoveredServerCapabilities();
        $discovered->record(new ServerCapabilities(extensions: ['com.example/other' => []]));
        $logger = new ArrayLogger();
        $handler = $this->buildGate($discovered, $delivered, $logger);

        $handler->handle(new TestNotification());

        self::assertSame(0, $delivered);
        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Dropping {method}: the server did not advertise extension {extension}.');
        self::assertCount(1, $matches);
        self::assertSame(
            ['method' => TestNotification::getMethod(), 'extension' => 'com.example/feature'],
            $matches[0]['context'],
        );
    }

    public function testDropsWhenTheServerAdvertisedNoExtensionsAtAll(): void
    {
        $delivered = 0;
        $discovered = new DiscoveredServerCapabilities();
        $discovered->record(new ServerCapabilities(tools: []));
        $handler = $this->buildGate($discovered, $delivered);

        $handler->handle(new TestNotification());

        self::assertSame(0, $delivered);
    }

    private function buildGate(
        DiscoveredServerCapabilities $discovered,
        int &$delivered,
        ?ArrayLogger $logger = null,
    ): ExtensionGateNotificationHandler {
        return new ExtensionGateNotificationHandler(
            'com.example/feature',
            new ClosureNotificationHandler(static function () use (&$delivered): void {
                ++$delivered;
            }),
            $discovered,
            $logger ?? new ArrayLogger(),
        );
    }
}
