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

use Nexus\Mcp\Client\Exception\PkceNotSupportedException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(PkceNotSupportedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class PkceNotSupportedExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesTheAuthorizationServer(): void
    {
        self::assertSame(
            'The authorization server "https://auth.example.com" does not advertise the S256 code challenge method, so authorization cannot proceed.',
            (new PkceNotSupportedException('https://auth.example.com'))->getMessage(),
        );
    }
}
