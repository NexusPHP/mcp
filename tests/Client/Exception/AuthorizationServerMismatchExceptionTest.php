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

use Nexus\Mcp\Client\Exception\AuthorizationServerMismatchException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(AuthorizationServerMismatchException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationServerMismatchExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesBothAuthorizationServers(): void
    {
        self::assertSame(
            'The supplied client credentials were registered with "https://old.example.com" but the protected resource now names "https://new.example.com", and credentials are not portable between authorization servers.',
            (new AuthorizationServerMismatchException('https://old.example.com', 'https://new.example.com'))->getMessage(),
        );
    }
}
