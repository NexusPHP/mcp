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

namespace Nexus\Mcp\Tests\Extension\Auth\Enterprise;

use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertion;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionType;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(IdentityAssertion::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class IdentityAssertionTest extends AbstractMcpTestCase
{
    public function testItCarriesTheTokenAndItsType(): void
    {
        $assertion = new IdentityAssertion('the-id-token', IdentityAssertionType::IdToken);

        self::assertSame('the-id-token', $assertion->token);
        self::assertSame(IdentityAssertionType::IdToken, $assertion->type);
    }

    public function testAnEmptyTokenIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('"token" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new IdentityAssertion('', IdentityAssertionType::RefreshToken);
    }
}
