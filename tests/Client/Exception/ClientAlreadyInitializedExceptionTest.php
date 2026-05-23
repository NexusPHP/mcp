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

use Nexus\Mcp\Client\Exception\ClientAlreadyInitializedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClientAlreadyInitializedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientAlreadyInitializedExceptionTest extends TestCase
{
    public function testMessageStatesTheHandshakeAlreadyStartedOrCompleted(): void
    {
        self::assertSame(
            'Client handshake already started or completed.',
            new ClientAlreadyInitializedException()->getMessage(),
        );
    }
}
