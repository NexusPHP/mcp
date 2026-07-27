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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ClientRegistration::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientRegistrationTest extends TestCase
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
    }
}
