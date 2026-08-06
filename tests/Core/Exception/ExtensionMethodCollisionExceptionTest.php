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

namespace Nexus\Mcp\Tests\Core\Exception;

use Nexus\Mcp\Core\Exception\ExtensionMethodCollisionException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ExtensionMethodCollisionException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ExtensionMethodCollisionExceptionTest extends AbstractMcpTestCase
{
    public function testRequestMessageNamesTheClaimantAndTheOwner(): void
    {
        self::assertSame(
            'Extension "com.example/feature" cannot claim the request method "tools/call" already owned by the MCP specification.',
            (new ExtensionMethodCollisionException('Extension "com.example/feature"', 'tools/call', 'the MCP specification'))->getMessage(),
        );
    }

    public function testNotificationMessageNamesTheClaimantAndTheOwner(): void
    {
        self::assertSame(
            'A builder-registered handler cannot claim the notification method "acme/ping" already owned by extension "acme/other".',
            (new ExtensionMethodCollisionException('A builder-registered handler', 'acme/ping', 'extension "acme/other"', isNotification: true))->getMessage(),
        );
    }
}
