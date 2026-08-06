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

namespace Nexus\Mcp\Tests\Server\Exception;

use Nexus\Mcp\Server\Exception\UnreservedMethodException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(UnreservedMethodException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class UnreservedMethodExceptionTest extends AbstractMcpTestCase
{
    public function testRequestMessagePointsToAddRequestHandler(): void
    {
        self::assertSame(
            'Request method "acme/snapshot" is not reserved by the MCP specification. Use addRequestHandler() to register a vendor extension.',
            (new UnreservedMethodException('acme/snapshot'))->getMessage(),
        );
    }

    public function testNotificationMessagePointsToAddNotificationHandler(): void
    {
        self::assertSame(
            'Notification method "acme/snapshot-done" is not reserved by the MCP specification. Use addNotificationHandler() to register a vendor extension.',
            (new UnreservedMethodException('acme/snapshot-done', isNotification: true))->getMessage(),
        );
    }
}
