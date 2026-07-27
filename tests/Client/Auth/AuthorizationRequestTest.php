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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Client\Auth\AuthorizationRedirect;
use Nexus\Mcp\Client\Auth\AuthorizationRequest;
use Nexus\Mcp\Client\Auth\PkcePair;
use Nexus\Mcp\Client\Exception\UntrustedAuthorizationMetadataException;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuthorizationRequest::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationRequestTest extends TestCase
{
    public function testBuildCarriesEveryRequiredParameter(): void
    {
        $redirect = self::build(new ScopeSet(['files:read', 'files:write']));

        self::assertSame([
            'response_type' => 'code',
            'client_id' => 'https://app.example.com/client.json',
            'redirect_uri' => 'http://localhost:3000/callback',
            'state' => $redirect->state,
            'code_challenge' => $redirect->pkce->challenge,
            'code_challenge_method' => 'S256',
            'resource' => 'https://mcp.example.com/mcp',
            'scope' => 'files:read files:write',
        ], self::readQuery($redirect->url));
    }

    public function testBuildTargetsTheAuthorizationEndpoint(): void
    {
        self::assertStringStartsWith('https://auth.example.com/authorize?', self::build(new ScopeSet())->url);
    }

    public function testBuildOmitsTheScopeParameterForAnEmptyScopeSet(): void
    {
        self::assertArrayNotHasKey('scope', self::readQuery(self::build(new ScopeSet())->url));
    }

    public function testBuildKeepsAQueryTheAuthorizationEndpointAlreadyCarries(): void
    {
        $redirect = self::build(new ScopeSet(), 'https://auth.example.com/authorize?tenant=acme');

        self::assertStringStartsWith('https://auth.example.com/authorize?tenant=acme&', $redirect->url);
        self::assertSame('acme', self::readQuery($redirect->url)['tenant'] ?? null);
    }

    public function testBuildForcesAConsentScreenWhenItAsksForOfflineAccess(): void
    {
        $redirect = self::build(new ScopeSet(['files:read', 'offline_access']));

        self::assertSame('consent', self::readQuery($redirect->url)['prompt'] ?? null);
    }

    public function testBuildLeavesTheConsentScreenToTheServerOtherwise(): void
    {
        self::assertArrayNotHasKey('prompt', self::readQuery(self::build(new ScopeSet(['files:read']))->url));
    }

    public function testBuildRecordsTheIssuerToValidateTheResponseAgainst(): void
    {
        self::assertSame('https://auth.example.com', self::build(new ScopeSet())->expectedIssuer);
    }

    public function testBuildRecordsTheRequestedScopes(): void
    {
        self::assertSame(['files:read'], self::build(new ScopeSet(['files:read']))->requestedScopes->values);
    }

    public function testBuildRequiresTheIssuerParameterWhenTheServerAdvertisesIt(): void
    {
        self::assertTrue(self::build(new ScopeSet(), issuerParameterSupported: true)->issuerParameterRequired);
    }

    public function testBuildDoesNotRequireTheIssuerParameterWhenTheServerDeniesIt(): void
    {
        self::assertFalse(self::build(new ScopeSet(), issuerParameterSupported: false)->issuerParameterRequired);
    }

    public function testBuildDoesNotRequireTheIssuerParameterWhenTheServerIsSilent(): void
    {
        self::assertFalse(self::build(new ScopeSet())->issuerParameterRequired);
    }

    public function testBuildProducesAFreshStateAndVerifierEachTime(): void
    {
        $first = self::build(new ScopeSet());
        $second = self::build(new ScopeSet());

        self::assertNotSame($first->state, $second->state);
        self::assertNotSame($first->pkce->verifier, $second->pkce->verifier);
        self::assertSame(64, \strlen($first->state));
    }

    public function testBuildDerivesTheChallengeFromTheVerifierItRecorded(): void
    {
        $redirect = self::build(new ScopeSet());

        self::assertSame(PkcePair::fromVerifier($redirect->pkce->verifier)->challenge, $redirect->pkce->challenge);
    }

    public function testBuildRejectsAServerWithNoAuthorizationEndpoint(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" publishes no authorization endpoint.');

        AuthorizationRequest::build(
            new AuthorizationServerMetadata('https://auth.example.com'),
            'client-id',
            'http://localhost:3000/callback',
            new ResourceIdentifier('https://mcp.example.com/mcp'),
            new ScopeSet(),
        );
    }

    public function testBuildRefusesAnInsecureAuthorizationEndpoint(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the authorization endpoint "http://auth.example.com/authorize" is not served over HTTPS.');

        self::build(new ScopeSet(), 'http://auth.example.com/authorize');
    }

    public function testBuildRefusesANonHttpAuthorizationEndpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The authorization endpoint must be an absolute URL, "javascript:alert(1)//" given.');

        self::build(new ScopeSet(), 'javascript:alert(1)//');
    }

    /**
     * @param non-empty-string $authorizationEndpoint
     */
    private static function build(
        ScopeSet $scopes,
        string $authorizationEndpoint = 'https://auth.example.com/authorize',
        ?bool $issuerParameterSupported = null,
    ): AuthorizationRedirect {
        return AuthorizationRequest::build(
            new AuthorizationServerMetadata(
                'https://auth.example.com',
                $authorizationEndpoint,
                'https://auth.example.com/token',
                authorizationResponseIssParameterSupported: $issuerParameterSupported,
            ),
            'https://app.example.com/client.json',
            'http://localhost:3000/callback',
            new ResourceIdentifier('https://mcp.example.com/mcp'),
            $scopes,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function readQuery(string $url): array
    {
        $query = parse_url($url, \PHP_URL_QUERY);

        if (! \is_string($query)) {
            self::fail(\sprintf('Expected "%s" to carry a query string.', $url));
        }

        parse_str($query, $parsed);

        $parameters = [];

        foreach ($parsed as $name => $value) {
            if (\is_string($value)) {
                $parameters[(string) $name] = $value;
            }
        }

        return $parameters;
    }
}
