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

namespace Nexus\Mcp\Tests\Client\Auth;

use Nexus\Mcp\Client\Auth\AuthorizationRedirect;
use Nexus\Mcp\Client\Auth\PkcePair;
use Nexus\Mcp\Core\Auth\ScopeSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuthorizationRedirect::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationRedirectTest extends TestCase
{
    public function testItCarriesThePerRequestStateTheResponseIsValidatedAgainst(): void
    {
        $pkce = PkcePair::fromVerifier('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');
        $scopes = new ScopeSet(['files:read']);

        $redirect = new AuthorizationRedirect(
            'https://auth.example.com/authorize?response_type=code',
            'the-state',
            'https://auth.example.com',
            true,
            $pkce,
            $scopes,
        );

        self::assertSame('https://auth.example.com/authorize?response_type=code', $redirect->url);
        self::assertSame('the-state', $redirect->state);
        self::assertSame('https://auth.example.com', $redirect->expectedIssuer);
        self::assertTrue($redirect->issuerParameterRequired);
        self::assertSame($pkce, $redirect->pkce);
        self::assertSame($scopes, $redirect->requestedScopes);
    }
}
