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

use Nexus\Mcp\Client\Exception\ClientRegistrationRequiredException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ClientRegistrationRequiredException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientRegistrationRequiredExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesTheAuthorizationServer(): void
    {
        self::assertSame(
            'The authorization server "https://auth.example.com" supports neither Client ID Metadata Documents nor Dynamic Client Registration, so a client identifier must be supplied for it.',
            (new ClientRegistrationRequiredException('https://auth.example.com'))->getMessage(),
        );
    }
}
