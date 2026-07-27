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

use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Exception\InsecureAuthorizationEndpointException;
use Nexus\Mcp\Core\Auth\ApplicationType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuthorizationOptions::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationOptionsTest extends TestCase
{
    public function testItCarriesEveryConfiguredField(): void
    {
        $preRegistered = new ClientRegistration('the-client', 'https://auth.example.com');

        $options = new AuthorizationOptions(
            'Example MCP Client',
            'http://localhost:3000/callback',
            'https://app.example.com/client.json',
            $preRegistered,
            ApplicationType::Web,
            5,
            true,
        );

        self::assertSame('Example MCP Client', $options->clientName);
        self::assertSame('http://localhost:3000/callback', $options->redirectUri);
        self::assertSame('https://app.example.com/client.json', $options->clientIdMetadataDocumentUrl);
        self::assertSame($preRegistered, $options->preRegistered);
        self::assertSame(ApplicationType::Web, $options->applicationType);
        self::assertSame(5, $options->maxScopeUpgrades);
        self::assertTrue($options->requestOfflineAccess);
    }

    public function testARemoteCleartextRedirectUriIsRefused(): void
    {
        $this->expectException(InsecureAuthorizationEndpointException::class);
        $this->expectExceptionMessageIs('The redirect URI must be served over HTTPS or from a loopback host, "http://attacker.example.com/cb" given.');

        new AuthorizationOptions('Example MCP Client', 'http://attacker.example.com/cb');
    }

    public function testAnHttpsRedirectUriIsAccepted(): void
    {
        self::assertSame(
            'https://app.example.com/cb',
            new AuthorizationOptions('Example MCP Client', 'https://app.example.com/cb')->redirectUri,
        );
    }

    public function testItDefaultsToANativePublicClient(): void
    {
        $options = new AuthorizationOptions('Example MCP Client', 'http://localhost:3000/callback');

        self::assertNull($options->clientIdMetadataDocumentUrl);
        self::assertNull($options->preRegistered);
        self::assertSame(ApplicationType::Native, $options->applicationType);
        self::assertSame(2, $options->maxScopeUpgrades);
        self::assertFalse($options->requestOfflineAccess);
    }
}
