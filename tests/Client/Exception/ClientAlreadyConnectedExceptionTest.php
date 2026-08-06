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

use Nexus\Mcp\Client\Exception\ClientAlreadyConnectedException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ClientAlreadyConnectedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientAlreadyConnectedExceptionTest extends AbstractMcpTestCase
{
    public function testMessageStatesTheClientIsAlreadyConnected(): void
    {
        self::assertSame(
            'Client is already connected to a transport.',
            (new ClientAlreadyConnectedException())->getMessage(),
        );
    }
}
