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

use Nexus\Mcp\Server\Exception\ReservedMethodException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ReservedMethodException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReservedMethodExceptionTest extends AbstractMcpTestCase
{
    public function testRequestMessagePointsToReplaceRequestHandler(): void
    {
        self::assertSame(
            'Request method "tools/list" is reserved by the MCP specification. Use replaceRequestHandler() to attach a handler to it.',
            (new ReservedMethodException('tools/list'))->getMessage(),
        );
    }

    public function testNotificationMessagePointsToReplaceNotificationHandler(): void
    {
        self::assertSame(
            'Notification method "notifications/initialized" is reserved by the MCP specification. Use replaceNotificationHandler() to attach a handler to it.',
            (new ReservedMethodException('notifications/initialized', isNotification: true))->getMessage(),
        );
    }
}
