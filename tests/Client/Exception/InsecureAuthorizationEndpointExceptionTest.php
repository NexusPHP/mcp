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

use Nexus\Mcp\Client\Exception\InsecureAuthorizationEndpointException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InsecureAuthorizationEndpointException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class InsecureAuthorizationEndpointExceptionTest extends TestCase
{
    public function testMessageNamesTheEndpointAndItsUrl(): void
    {
        self::assertSame(
            'The registration endpoint must be served over HTTPS or from a loopback host, "http://auth.example.com/register" given.',
            new InsecureAuthorizationEndpointException('registration endpoint', 'http://auth.example.com/register')->getMessage(),
        );
    }
}
