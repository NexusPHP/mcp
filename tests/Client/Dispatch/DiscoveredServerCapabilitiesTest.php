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

namespace Nexus\Mcp\Tests\Client\Dispatch;

use Nexus\Mcp\Client\Dispatch\DiscoveredServerCapabilities;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(DiscoveredServerCapabilities::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class DiscoveredServerCapabilitiesTest extends AbstractMcpTestCase
{
    public function testHoldsNothingUntilARecording(): void
    {
        self::assertNull((new DiscoveredServerCapabilities())->current());
    }

    public function testRecordsAndReplacesTheCapabilities(): void
    {
        $discovered = new DiscoveredServerCapabilities();
        $capabilities = new ServerCapabilities(tools: []);

        $discovered->record($capabilities);
        self::assertSame($capabilities, $discovered->current());

        $discovered->record(null);
        self::assertNull($discovered->current());
    }
}
