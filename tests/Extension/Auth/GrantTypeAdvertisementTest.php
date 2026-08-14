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

namespace Nexus\Mcp\Tests\Extension\Auth;

use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Exception\RuntimeException;
use Nexus\Mcp\Extension\Auth\GrantTypeAdvertisement;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(GrantTypeAdvertisement::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class GrantTypeAdvertisementTest extends AbstractMcpTestCase
{
    private const string ISSUER = 'https://auth.example.com';

    public function testAnAdvertisedGrantTypePasses(): void
    {
        $this->expectNotToPerformAssertions();

        GrantTypeAdvertisement::verify(
            new AuthorizationServerMetadata(self::ISSUER, grantTypesSupported: ['authorization_code', 'client_credentials']),
            'client_credentials',
        );
    }

    public function testAServerPublishingNoListIsTakenOnTrust(): void
    {
        $this->expectNotToPerformAssertions();

        GrantTypeAdvertisement::verify(new AuthorizationServerMetadata(self::ISSUER), 'client_credentials');
    }

    public function testAListWithoutTheGrantTypeIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not advertise the "client_credentials" grant type.');

        GrantTypeAdvertisement::verify(
            new AuthorizationServerMetadata(self::ISSUER, grantTypesSupported: ['authorization_code']),
            'client_credentials',
        );
    }

    public function testAnEmptyListRefusesEveryGrantType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not advertise the "urn:ietf:params:oauth:grant-type:jwt-bearer" grant type.');

        GrantTypeAdvertisement::verify(
            new AuthorizationServerMetadata(self::ISSUER, grantTypesSupported: []),
            'urn:ietf:params:oauth:grant-type:jwt-bearer',
        );
    }

    public function testAHostileIssuerIsBoundedAndEscapedInTheRefusal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The authorization server "%s..." does not advertise the "client_credentials" grant type.',
            'https://'.str_repeat('a', 245),
        ));

        GrantTypeAdvertisement::verify(
            new AuthorizationServerMetadata('https://'.str_repeat('a', 300)."\x1b", grantTypesSupported: ['authorization_code']),
            'client_credentials',
        );
    }
}
