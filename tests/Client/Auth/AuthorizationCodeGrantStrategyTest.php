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

use Amp\NullCancellation;
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Client\Auth\AuthorizationCodeGrantStrategy;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\ClientRegistrar;
use Nexus\Mcp\Client\Auth\DiscoveredResource;
use Nexus\Mcp\Client\Auth\GrantContext;
use Nexus\Mcp\Client\Auth\InMemoryClientRegistrationStore;
use Nexus\Mcp\Client\Auth\TokenEndpoint;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Auth\ProtectedResourceMetadata;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Tests\Fixtures\Client\Auth\ScriptedUserAuthorization;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuthorizationCodeGrantStrategy::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationCodeGrantStrategyTest extends TestCase
{
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string ISSUER = 'https://auth.example.com';

    public function testGrantRegistersAuthorizesAndExchangesTheCode(): void
    {
        $http = new RecordingHttpClient()
            ->willAnswerJson(['client_id' => 'the-registered-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
        ;
        $user = new ScriptedUserAuthorization();

        $token = new AuthorizationCodeGrantStrategy($user)->grant(self::context($http, new ScopeSet(['files:read'])), new NullCancellation());

        self::assertSame('the-access-token', $token->value);
        self::assertSame(self::ISSUER, $token->issuer);
        self::assertSame(['files:read'], $user->readRequestedScopes());
        self::assertSame('https://auth.example.com/register', (string) $http->readRequest(0)->getUri());
        self::assertSame('https://auth.example.com/token', (string) $http->readRequest(1)->getUri());
    }

    public function testGrantRefusesToRunWithoutARedirectUri(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('The authorization-code grant needs a redirect URI, and the authorization options carry none.');

        new AuthorizationCodeGrantStrategy(new ScriptedUserAuthorization())->grant(
            self::context(new RecordingHttpClient(), new ScopeSet(), new AuthorizationOptions('Example MCP Client')),
            new NullCancellation(),
        );
    }

    public function testItRenewsThroughTheChallengeRatherThanByAFreshGrant(): void
    {
        self::assertFalse(new AuthorizationCodeGrantStrategy(new ScriptedUserAuthorization())->renewsByFreshGrant());
    }

    private static function context(
        RecordingHttpClient $http,
        ScopeSet $scopes,
        ?AuthorizationOptions $options = null,
    ): GrantContext {
        $resource = new ResourceIdentifier(self::RESOURCE);

        return new GrantContext(
            new DiscoveredResource(
                new ProtectedResourceMetadata($resource, [self::ISSUER]),
                new AuthorizationServerMetadata(
                    self::ISSUER,
                    authorizationEndpoint: 'https://auth.example.com/authorize',
                    tokenEndpoint: 'https://auth.example.com/token',
                    registrationEndpoint: 'https://auth.example.com/register',
                    codeChallengeMethodsSupported: ['S256'],
                ),
            ),
            $resource,
            $scopes,
            $options ?? new AuthorizationOptions('Example MCP Client', 'http://localhost:3000/callback'),
            new ClientRegistrar($http, new InMemoryClientRegistrationStore()),
            new TokenEndpoint($http),
            $http,
            new ArrayLogger(),
        );
    }
}
