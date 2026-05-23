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

use Nexus\Mcp\Client\Exception\ClientNotInitializedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClientNotInitializedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientNotInitializedExceptionTest extends TestCase
{
    public function testMessageNamesTheRejectedMethod(): void
    {
        self::assertSame(
            'Request method "tools/list" cannot be sent before the client handshake completes.',
            new ClientNotInitializedException('tools/list')->getMessage(),
        );
    }
}
