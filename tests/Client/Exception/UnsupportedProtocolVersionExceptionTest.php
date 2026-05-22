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

namespace Nexus\Mcp\Tests\Client\Exception;

use Nexus\Mcp\Client\Exception\UnsupportedProtocolVersionException;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UnsupportedProtocolVersionException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class UnsupportedProtocolVersionExceptionTest extends TestCase
{
    public function testRetainsTheNegotiatedVersion(): void
    {
        $negotiated = new ProtocolVersion('2024-11-05');
        $exception = new UnsupportedProtocolVersionException($negotiated);

        self::assertSame($negotiated, $exception->negotiated);
    }

    public function testMessageNamesBothTheNegotiatedAndSupportedVersions(): void
    {
        $exception = new UnsupportedProtocolVersionException(new ProtocolVersion('2024-11-05'));

        self::assertStringContainsString('2024-11-05', $exception->getMessage());
        self::assertStringContainsString(ProtocolVersion::LATEST_VERSION, $exception->getMessage());
    }
}
