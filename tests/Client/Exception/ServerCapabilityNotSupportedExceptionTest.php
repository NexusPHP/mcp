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

use Nexus\Mcp\Client\Exception\ServerCapabilityNotSupportedException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ServerCapabilityNotSupportedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ServerCapabilityNotSupportedExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesTheRequestMethod(): void
    {
        $exception = new ServerCapabilityNotSupportedException('tools/call');

        self::assertSame(
            'Request method "tools/call" requires a server capability that was not advertised by server/discover. Check getServerCapabilities() before calling.',
            $exception->getMessage(),
        );
    }
}
