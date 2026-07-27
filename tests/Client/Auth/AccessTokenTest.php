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

use Nexus\Mcp\Client\Auth\AccessToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AccessToken::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AccessTokenTest extends TestCase
{
    public function testItCarriesEveryIssuedField(): void
    {
        $token = new AccessToken('the-value', 'https://auth.example.com', 1_800_000_000, 'the-refresh-token', ['files:read']);

        self::assertSame('the-value', $token->value);
        self::assertSame('https://auth.example.com', $token->issuer);
        self::assertSame(1_800_000_000, $token->expiresAt);
        self::assertSame('the-refresh-token', $token->refreshToken);
        self::assertSame(['files:read'], $token->scopes);
    }

    public function testAnUnadornedTokenHasNoExpiryRefreshOrScopes(): void
    {
        $token = new AccessToken('the-value', 'https://auth.example.com');

        self::assertNull($token->expiresAt);
        self::assertNull($token->refreshToken);
        self::assertSame([], $token->scopes);
    }
}
