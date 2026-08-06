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
use Nexus\Mcp\Client\Auth\InsufficientScopePolicy;
use Nexus\Mcp\Client\Exception\InsecureAuthorizationEndpointException;
use Nexus\Mcp\Core\Auth\ApplicationType;
use Nexus\Mcp\Core\Auth\TokenEndpointAuthMethod;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(AuthorizationOptions::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationOptionsTest extends AbstractMcpTestCase
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
            ['files:read'],
            InsufficientScopePolicy::Fail,
            2.5,
            true,
        );

        self::assertSame('Example MCP Client', $options->clientName);
        self::assertSame('http://localhost:3000/callback', $options->redirectUri);
        self::assertSame('https://app.example.com/client.json', $options->clientIdMetadataDocumentUrl);
        self::assertSame($preRegistered, $options->preRegistered);
        self::assertSame(ApplicationType::Web, $options->applicationType);
        self::assertSame(5, $options->maxScopeUpgrades);
        self::assertTrue($options->requestOfflineAccess);
        self::assertSame(['files:read'], $options->defaultScopes);
        self::assertSame(InsufficientScopePolicy::Fail, $options->onInsufficientScope);
        self::assertSame(2.5, $options->timeout);
        self::assertTrue($options->allowInsecureLoopback);
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
            (new AuthorizationOptions('Example MCP Client', 'https://app.example.com/cb'))->redirectUri,
        );
    }

    public function testTheRedirectUriMayBeLeftOutForGrantsThatNeverVisitAnAuthorizationEndpoint(): void
    {
        self::assertNull((new AuthorizationOptions('Example MCP Client'))->redirectUri);
    }

    public function testAPreRegisteredClientAuthenticatingWithAJwtAssertionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Pre-registered credentials cannot authenticate with "private_key_jwt". Configure a ClientCredentialsGrant with a PrivateKeyJwtCredential instead.');

        new AuthorizationOptions(
            'Example MCP Client',
            preRegistered: new ClientRegistration('the-client', null, null, TokenEndpointAuthMethod::PrivateKeyJwt),
        );
    }

    #[DataProvider('provideAMetadataDocumentUrlOffTheSpecsShapeIsRefusedCases')]
    public function testAMetadataDocumentUrlOffTheSpecsShapeIsRefused(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The Client ID Metadata Document URL must be an HTTPS URL carrying a path component, "%s" given.',
            $url,
        ));

        new AuthorizationOptions('Example MCP Client', 'http://localhost:3000/callback', $url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAMetadataDocumentUrlOffTheSpecsShapeIsRefusedCases(): iterable
    {
        yield 'cleartext is refused' => ['http://app.example.com/client.json'];

        yield 'loopback earns no exemption' => ['http://localhost:3000/client.json'];

        yield 'a bare host carries no path' => ['https://app.example.com'];

        yield 'a root path is no path' => ['https://app.example.com/'];
    }

    public function testItDefaultsToANativePublicClient(): void
    {
        $options = new AuthorizationOptions('Example MCP Client', 'http://localhost:3000/callback');

        self::assertNull($options->clientIdMetadataDocumentUrl);
        self::assertNull($options->preRegistered);
        self::assertSame(ApplicationType::Native, $options->applicationType);
        self::assertSame(2, $options->maxScopeUpgrades);
        self::assertFalse($options->requestOfflineAccess);
        self::assertSame([], $options->defaultScopes);
        self::assertSame(InsufficientScopePolicy::Reauthorize, $options->onInsufficientScope);
        self::assertSame(10.0, $options->timeout);
        // A cleartext authorization server is opt-in, never the default.
        self::assertFalse($options->allowInsecureLoopback);
    }
}
