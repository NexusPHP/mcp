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

namespace Nexus\Mcp\Tests\Extension\Auth\Exception;

use Nexus\Mcp\Extension\Auth\Exception\IdentityAssertionExchangeFailedException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(IdentityAssertionExchangeFailedException::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class IdentityAssertionExchangeFailedExceptionTest extends AbstractMcpTestCase
{
    public function testCarriesItsMessage(): void
    {
        self::assertSame(
            'The exchange was refused.',
            (new IdentityAssertionExchangeFailedException('The exchange was refused.'))->getMessage(),
        );
    }
}
