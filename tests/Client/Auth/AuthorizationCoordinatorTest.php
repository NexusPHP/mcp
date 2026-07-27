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

use Amp\DeferredFuture;
use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\AuthorizationCoordinator;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\ClientRegistrar;
use Nexus\Mcp\Client\Auth\InMemoryClientRegistrationStore;
use Nexus\Mcp\Client\Auth\InMemoryTokenStore;
use Nexus\Mcp\Client\Auth\MetadataDiscovery;
use Nexus\Mcp\Client\Auth\TokenEndpoint;
use Nexus\Mcp\Client\Exception\InvalidAuthorizationResponseException;
use Nexus\Mcp\Client\Exception\TokenRequestFailedException;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Core\Auth\WwwAuthenticateChallenge;
use Nexus\Mcp\Tests\Fixtures\Client\Auth\ScriptedUserAuthorization;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(AuthorizationCoordinator::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationCoordinatorTest extends TestCase
{
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string ISSUER = 'https://auth.example.com';

    public function testAuthorizeWalksDiscoveryRegistrationAndTheTokenExchange(): void
    {
        $http = self::scriptFullFlow();
        $user = new ScriptedUserAuthorization();

        $token = self::coordinator($http, $user)->authorize(self::resource());

        self::assertSame('the-access-token', $token->value);
        self::assertSame([
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
            'https://auth.example.com/.well-known/oauth-authorization-server',
            'https://auth.example.com/register',
            'https://auth.example.com/token',
        ], array_map(static fn($request): string => (string) $request->getUri(), $http->requests));
        self::assertCount(1, $user->redirects);
    }

    public function testAuthorizeStoresTheTokenAgainstTheIssuer(): void
    {
        $store = new InMemoryTokenStore();

        self::coordinator(self::scriptFullFlow(), new ScriptedUserAuthorization(), $store)->authorize(self::resource());

        self::assertSame('the-access-token', $store->read(self::RESOURCE, self::ISSUER)?->value);
    }

    public function testTheChallengeScopeOutranksTheAdvertisedScopes(): void
    {
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(['scopes_supported' => ['files:read']]), $user)->authorize(
            self::resource(),
            new WwwAuthenticateChallenge('Bearer', ['scope' => 'files:write']),
        );

        self::assertSame(['files:write'], $user->readRequestedScopes());
    }

    public function testTheAdvertisedScopesAreRequestedWhenNoChallengeNamesAny(): void
    {
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(['scopes_supported' => ['files:read', 'files:write']]), $user)->authorize(self::resource());

        self::assertSame(['files:read', 'files:write'], $user->readRequestedScopes());
    }

    public function testNoScopeIsRequestedWhenNothingAdvertisesAny(): void
    {
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(), $user)->authorize(self::resource());

        self::assertSame([], $user->readRequestedScopes());
    }

    public function testAdditionalScopesAreAccumulatedOntoTheSelection(): void
    {
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(['scopes_supported' => ['files:read']]), $user)->authorize(
            self::resource(),
            null,
            new ScopeSet(['files:write']),
        );

        self::assertSame(['files:read', 'files:write'], $user->readRequestedScopes());
    }

    public function testAlreadyGrantedScopesSurviveAStepUp(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, self::ISSUER, new AccessToken('the-old-token', scopes: ['files:read']));
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(), $user, $store)->authorize(
            self::resource(),
            new WwwAuthenticateChallenge('Bearer', ['scope' => 'files:write']),
        );

        self::assertSame(['files:write', 'files:read'], $user->readRequestedScopes());
    }

    public function testOfflineAccessIsRequestedWhenItIsAskedForAndTheServerOffersIt(): void
    {
        $user = new ScriptedUserAuthorization();
        $http = self::scriptFullFlow(serverOverrides: ['scopes_supported' => ['files:read', 'offline_access']]);

        self::coordinator($http, $user, offlineAccess: true)->authorize(self::resource());

        self::assertSame(['offline_access'], $user->readRequestedScopes());
    }

    public function testOfflineAccessIsNotRequestedWhenTheServerDoesNotOfferIt(): void
    {
        $user = new ScriptedUserAuthorization();
        $http = self::scriptFullFlow(serverOverrides: ['scopes_supported' => ['files:read']]);

        self::coordinator($http, $user, offlineAccess: true)->authorize(self::resource());

        self::assertSame([], $user->readRequestedScopes());
    }

    public function testOfflineAccessIsNotRequestedUnlessItIsAskedFor(): void
    {
        $user = new ScriptedUserAuthorization();
        $http = self::scriptFullFlow(serverOverrides: ['scopes_supported' => ['files:read', 'offline_access']]);

        self::coordinator($http, $user)->authorize(self::resource());

        self::assertSame([], $user->readRequestedScopes());
    }

    public function testAFailedResponseValidationAbortsBeforeTheTokenExchange(): void
    {
        $http = self::scriptFullFlow();
        $user = new ScriptedUserAuthorization(['error' => 'access_denied', 'iss' => 'https://attacker.example']);

        $this->expectException(InvalidAuthorizationResponseException::class);

        self::coordinator($http, $user)->authorize(self::resource());
    }

    public function testAuthorizeLogsTheServerItAuthorizedAgainst(): void
    {
        $logger = new ArrayLogger();

        self::coordinator(self::scriptFullFlow(), new ScriptedUserAuthorization(), logger: $logger)->authorize(self::resource());

        self::assertSame(
            [['level' => LogLevel::INFO, 'message' => 'Authorized {resource} at {issuer}.', 'context' => [
                'resource' => self::RESOURCE,
                'issuer' => self::ISSUER,
            ]]],
            $logger->recordsMatching(LogLevel::INFO, 'Authorized {resource} at {issuer}.'),
        );
    }

    public function testARefusedRefreshIsLoggedWithItsReason(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['error' => 'invalid_grant'], 400)
        ;
        $logger = new ArrayLogger();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), logger: $logger);
        $coordinator->authorize(self::resource());

        $coordinator->fetchToken(self::resource());

        $message = 'The refresh token for {resource} was refused, so a new authorization is needed. {reason}';
        self::assertSame(
            [['level' => LogLevel::INFO, 'message' => $message, 'context' => [
                'resource' => self::RESOURCE,
                'reason' => 'The token request failed with "invalid_grant".',
            ]]],
            $logger->recordsMatching(LogLevel::INFO, $message),
        );
    }

    public function testATokenLapsingInsideTheLeewayIsTreatedAsSpent(): void
    {
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator(self::scriptFullFlow(tokenOverrides: ['expires_in' => 30]), new ScriptedUserAuthorization(), $store);
        $coordinator->authorize(self::resource());

        self::assertNull($coordinator->fetchToken(self::resource()));
    }

    public function testATokenLapsingBeyondTheLeewayIsStillUsable(): void
    {
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator(self::scriptFullFlow(tokenOverrides: ['expires_in' => 90]), new ScriptedUserAuthorization(), $store);
        $coordinator->authorize(self::resource());

        self::assertSame('the-access-token', $coordinator->fetchToken(self::resource())?->value);
    }

    public function testFetchTokenReturnsNullBeforeAnyAuthorization(): void
    {
        self::assertNull(self::coordinator(new RecordingHttpClient(), new ScriptedUserAuthorization())->fetchToken(self::resource()));
    }

    public function testFetchTokenReturnsTheStoredToken(): void
    {
        $coordinator = self::coordinator(self::scriptFullFlow(), new ScriptedUserAuthorization());
        $coordinator->authorize(self::resource());

        self::assertSame('the-access-token', $coordinator->fetchToken(self::resource())?->value);
    }

    public function testFetchTokenKeepsAnUnexpiredToken(): void
    {
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator(self::scriptFullFlow(tokenOverrides: ['expires_in' => 3600]), new ScriptedUserAuthorization(), $store);
        $coordinator->authorize(self::resource());

        self::assertSame('the-access-token', $coordinator->fetchToken(self::resource())?->value);
    }

    public function testFetchTokenRenewsASpentTokenWithItsRefreshToken(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['access_token' => 'the-renewed-token', 'token_type' => 'Bearer'])
        ;
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);
        $coordinator->authorize(self::resource());

        self::assertSame('the-renewed-token', $coordinator->fetchToken(self::resource())?->value);
        self::assertSame('the-renewed-token', $store->read(self::RESOURCE, self::ISSUER)?->value);
    }

    public function testASecondRenewalRedeemsARefreshTokenTheServerDidNotRotate(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['access_token' => 'the-renewed-token', 'token_type' => 'Bearer', 'expires_in' => 1])
            ->willAnswerJson(['access_token' => 'the-re-renewed-token', 'token_type' => 'Bearer'])
        ;
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());
        $coordinator->authorize(self::resource());
        $coordinator->fetchToken(self::resource());

        self::assertSame('the-re-renewed-token', $coordinator->fetchToken(self::resource())?->value);
    }

    public function testFetchTokenDropsASpentTokenThatCannotBeRenewed(): void
    {
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator(self::scriptFullFlow(tokenOverrides: ['expires_in' => 1]), new ScriptedUserAuthorization(), $store);
        $coordinator->authorize(self::resource());

        self::assertNull($coordinator->fetchToken(self::resource()));
        self::assertNull($store->read(self::RESOURCE, self::ISSUER));
    }

    public function testFetchTokenDropsATokenWhoseRefreshIsRefused(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['error' => 'invalid_grant'], 400)
        ;
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);
        $coordinator->authorize(self::resource());

        self::assertNull($coordinator->fetchToken(self::resource()));
        self::assertNull($store->read(self::RESOURCE, self::ISSUER));
    }

    public function testConcurrentAuthorizationsRunOneFlowAndPromptOnce(): void
    {
        $gate = new DeferredFuture();
        $http = new RecordingHttpClient()
            ->willAnswerJson(self::resourceDocument(), gate: $gate->getFuture())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-registered-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
        ;
        $user = new ScriptedUserAuthorization();
        $coordinator = self::coordinator($http, $user);

        $first = async(static fn(): AccessToken => $coordinator->authorize(self::resource()));
        $second = async(static fn(): AccessToken => $coordinator->authorize(self::resource()));
        delay(0);
        $gate->complete();

        self::assertSame($first->await(), $second->await());
        self::assertCount(1, $user->redirects);
        self::assertCount(4, $http->requests);
    }

    public function testConcurrentRenewalsRedeemTheRefreshTokenOnce(): void
    {
        $gate = new DeferredFuture();
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['access_token' => 'the-renewed-token', 'token_type' => 'Bearer'], gate: $gate->getFuture())
        ;
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);
        $coordinator->authorize(self::resource());

        $first = async(static fn(): ?AccessToken => $coordinator->fetchToken(self::resource()));
        $second = async(static fn(): ?AccessToken => $coordinator->fetchToken(self::resource()));
        delay(0);
        $gate->complete();

        $firstToken = $first->await();
        $secondToken = $second->await();
        $renewed = $store->read(self::RESOURCE, self::ISSUER);

        self::assertSame('the-renewed-token', $renewed?->value);
        self::assertSame($renewed, $firstToken);
        self::assertSame($renewed, $secondToken);
        self::assertCount(5, $http->requests);
    }

    public function testCallersAskingForDifferentScopesDoNotShareOneGrant(): void
    {
        $gate = new DeferredFuture();
        $http = self::scriptFullFlow()
            ->willAnswerJson(['access_token' => 'the-narrow-token', 'token_type' => 'Bearer'], gate: $gate->getFuture())
            ->willAnswerJson(['access_token' => 'the-wide-token', 'token_type' => 'Bearer'])
        ;
        $user = new ScriptedUserAuthorization();
        $coordinator = self::coordinator($http, $user);

        // Warming discovery and the registration leaves the token endpoint as the only leg still to run.
        $coordinator->authorize(self::resource());

        $first = async(static fn(): AccessToken => $coordinator->authorize(self::resource()));
        $second = async(static fn(): AccessToken => $coordinator->authorize(
            self::resource(),
            null,
            new ScopeSet(['files:admin']),
        ));
        delay(0);
        $gate->complete();

        $first->await();
        $second->await();

        self::assertCount(3, $user->redirects);
        self::assertSame(['files:admin'], $user->readRequestedScopes(2));
    }

    public function testReadGrantedScopesReportsWhatTheStoredTokenCarries(): void
    {
        $http = self::scriptFullFlow(['scopes_supported' => ['files:read', 'files:write']]);
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());
        $coordinator->authorize(self::resource());

        self::assertSame(['files:read', 'files:write'], $coordinator->readGrantedScopes(self::resource())->values);
    }

    public function testReadGrantedScopesIsEmptyBeforeAnyAuthorization(): void
    {
        $coordinator = self::coordinator(new RecordingHttpClient(), new ScriptedUserAuthorization());

        self::assertSame([], $coordinator->readGrantedScopes(self::resource())->values);
    }

    public function testReadGrantedScopesIsEmptyOnceTheTokenIsDiscarded(): void
    {
        $http = self::scriptFullFlow(['scopes_supported' => ['files:read']]);
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());
        $coordinator->authorize(self::resource());
        $coordinator->discardToken(self::resource());

        self::assertSame([], $coordinator->readGrantedScopes(self::resource())->values);
    }

    public function testARefreshFailureThatIsNotAGrantRejectionSurfacesAndKeepsTheToken(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['error' => 'invalid_client'], 400)
        ;
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);
        $coordinator->authorize(self::resource());

        try {
            $coordinator->fetchToken(self::resource());
            self::fail('The refusal should have surfaced.');
        } catch (TokenRequestFailedException $e) {
            self::assertSame('The token request failed with "invalid_client".', $e->getMessage());
        }

        self::assertSame('the-access-token', $store->read(self::RESOURCE, self::ISSUER)?->value);
    }

    public function testDiscardTokenIsHarmlessBeforeAnyAuthorization(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, self::ISSUER, new AccessToken('untouched'));

        self::coordinator(new RecordingHttpClient(), new ScriptedUserAuthorization(), $store)->discardToken(self::resource());

        self::assertSame('untouched', $store->read(self::RESOURCE, self::ISSUER)?->value);
    }

    public function testDiscardTokenDropsTheStoredToken(): void
    {
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator(self::scriptFullFlow(), new ScriptedUserAuthorization(), $store);
        $coordinator->authorize(self::resource());

        $coordinator->discardToken(self::resource());

        self::assertNull($store->read(self::RESOURCE, self::ISSUER));
        self::assertNull($coordinator->fetchToken(self::resource()));
    }

    public function testDiscardDiscoveryMakesTheNextAuthorizationReadTheMetadataAfresh(): void
    {
        $http = self::scriptFullFlow()
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer'])
        ;
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());
        $coordinator->authorize(self::resource());

        $coordinator->discardDiscovery(self::resource());

        self::assertSame('the-second-token', $coordinator->authorize(self::resource())->value);
        self::assertCount(7, $http->requests);
    }

    public function testASecondAuthorizationReusesWhatDiscoveryAlreadyFound(): void
    {
        $http = self::scriptFullFlow()->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer']);
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());
        $coordinator->authorize(self::resource());

        self::assertSame('the-second-token', $coordinator->authorize(self::resource())->value);
        self::assertCount(5, $http->requests);
    }

    private static function resource(): ResourceIdentifier
    {
        return new ResourceIdentifier(self::RESOURCE);
    }

    private static function coordinator(
        RecordingHttpClient $http,
        ScriptedUserAuthorization $user,
        ?InMemoryTokenStore $tokens = null,
        ?ArrayLogger $logger = null,
        bool $offlineAccess = false,
    ): AuthorizationCoordinator {
        return new AuthorizationCoordinator(
            new MetadataDiscovery($http),
            new ClientRegistrar($http, new InMemoryClientRegistrationStore()),
            new TokenEndpoint($http),
            $user,
            $tokens ?? new InMemoryTokenStore(),
            new AuthorizationOptions(
                'Example MCP Client',
                'http://localhost:3000/callback',
                requestOfflineAccess: $offlineAccess,
            ),
            $logger ?? new ArrayLogger(),
        );
    }

    /**
     * @param array<string, mixed> $resourceOverrides
     * @param array<string, mixed> $serverOverrides
     * @param array<string, mixed> $tokenOverrides
     */
    private static function scriptFullFlow(
        array $resourceOverrides = [],
        array $serverOverrides = [],
        array $tokenOverrides = [],
    ): RecordingHttpClient {
        return new RecordingHttpClient()
            ->willAnswerJson(self::resourceDocument($resourceOverrides))
            ->willAnswerJson(self::serverDocument($serverOverrides))
            ->willAnswerJson(['client_id' => 'the-registered-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer', ...$tokenOverrides])
        ;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function resourceDocument(array $overrides = []): array
    {
        return ['resource' => self::RESOURCE, 'authorization_servers' => [self::ISSUER], ...$overrides];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function serverDocument(array $overrides = []): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'registration_endpoint' => 'https://auth.example.com/register',
            'code_challenge_methods_supported' => ['S256'],
            ...$overrides,
        ];
    }
}
