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

use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Core\Auth\TokenEndpointAuthMethod;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ClientRegistration::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientRegistrationTest extends AbstractMcpTestCase
{
    public function testItBindsTheIdentifierToItsIssuer(): void
    {
        $registration = new ClientRegistration(
            'the-client',
            'https://auth.example.com',
            'the-secret',
            TokenEndpointAuthMethod::ClientSecretBasic,
        );

        self::assertSame('the-client', $registration->clientId);
        self::assertSame('https://auth.example.com', $registration->issuer);
        self::assertSame('the-secret', $registration->clientSecret);
        self::assertSame(TokenEndpointAuthMethod::ClientSecretBasic, $registration->tokenEndpointAuthMethod);
    }

    public function testAClientWithNoSecretIsPublic(): void
    {
        $registration = new ClientRegistration('the-client', 'https://auth.example.com');

        self::assertNull($registration->clientSecret);
        self::assertSame(TokenEndpointAuthMethod::None, $registration->tokenEndpointAuthMethod);
        self::assertNull($registration->clientSecretExpiresAt);
    }

    public function testASecretCarriesTheInstantItExpires(): void
    {
        $registration = new ClientRegistration(
            'the-client',
            'https://auth.example.com',
            'the-secret',
            TokenEndpointAuthMethod::ClientSecretBasic,
            1_893_456_000,
        );

        self::assertSame(1_893_456_000, $registration->clientSecretExpiresAt);
    }
}
