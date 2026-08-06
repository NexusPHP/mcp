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

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\AuthorizationCallback;
use Nexus\Mcp\Client\Auth\AuthorizationCodeGrantStrategy;
use Nexus\Mcp\Client\Auth\AuthorizationCoordinator;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizationRedirect;
use Nexus\Mcp\Client\Auth\ClientRegistrar;
use Nexus\Mcp\Client\Auth\GrantStrategyInterface;
use Nexus\Mcp\Client\Auth\InMemoryClientRegistrationStore;
use Nexus\Mcp\Client\Auth\InMemoryTokenStore;
use Nexus\Mcp\Client\Auth\MetadataDiscovery;
use Nexus\Mcp\Client\Auth\TokenEndpoint;
use Nexus\Mcp\Client\Auth\UserAuthorizationInterface;
use Nexus\Mcp\Client\Exception\ClientRegistrationRejectedException;
use Nexus\Mcp\Client\Exception\InvalidAuthorizationResponseException;
use Nexus\Mcp\Client\Exception\TokenRequestFailedException;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Core\Auth\WwwAuthenticateChallenge;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Auth\ScriptedGrantStrategy;
use Nexus\Mcp\Tests\Fixtures\Client\Auth\ScriptedUserAuthorization;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(AuthorizationCoordinator::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationCoordinatorTest extends AbstractMcpTestCase
{
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string ISSUER = 'https://auth.example.com';
    private const string FORMER_ISSUER = 'https://former-auth.example.com';

    public function testAuthorizeWalksDiscoveryRegistrationAndTheTokenExchange(): void
    {
        $http = self::scriptFullFlow();
        $user = new ScriptedUserAuthorization();

        $token = self::coordinator($http, $user)->reauthorize(null, null, new NullCancellation());

        self::assertSame('the-access-token', $token->value);
        self::assertSame([
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
            'https://auth.example.com/.well-known/oauth-authorization-server',
            'https://auth.example.com/register',
            'https://auth.example.com/token',
        ], array_map(static fn($request): string => (string) $request->getUri(), $http->requests));
        self::assertCount(1, $user->redirects);
    }

    public function testTheFirstAuthorizationServerTheResourcePublishesIsTheOneProbed(): void
    {
        $http = self::scriptFullFlow(['authorization_servers' => [self::ISSUER, 'https://spare-auth.example.com']]);

        self::coordinator($http, new ScriptedUserAuthorization())->reauthorize(null, null, new NullCancellation());

        self::assertSame(
            'https://auth.example.com/.well-known/oauth-authorization-server',
            (string) $http->readRequest(1)->getUri(),
        );
    }

    public function testAuthorizeStampsTheIssuerOnTheStoredToken(): void
    {
        $store = new InMemoryTokenStore();

        self::coordinator(self::scriptFullFlow(), new ScriptedUserAuthorization(), $store)->reauthorize(null, null, new NullCancellation());

        $token = $store->read(self::RESOURCE);
        self::assertSame('the-access-token', $token?->value);
        self::assertSame(self::ISSUER, $token->issuer);
    }

    public function testTheChallengeScopeOutranksTheAdvertisedScopes(): void
    {
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(['scopes_supported' => ['files:read']]), $user)->reauthorize(
            null,
            new WwwAuthenticateChallenge('Bearer', ['scope' => 'files:write']),
            new NullCancellation(),
        );

        self::assertSame(['files:write'], $user->readRequestedScopes());
    }

    public function testTheAdvertisedScopesAreRequestedWhenNoChallengeNamesAny(): void
    {
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(['scopes_supported' => ['files:read', 'files:write']]), $user)->reauthorize(null, null, new NullCancellation());

        self::assertSame(['files:read', 'files:write'], $user->readRequestedScopes());
    }

    public function testNoScopeIsRequestedWhenNothingAdvertisesAny(): void
    {
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(), $user)->reauthorize(null, null, new NullCancellation());

        self::assertSame([], $user->readRequestedScopes());
    }

    public function testTheDeclaredScopesStandInForEverythingTheResourceAdvertises(): void
    {
        $user = new ScriptedUserAuthorization();
        $http = self::scriptFullFlow(['scopes_supported' => ['files:read', 'files:write', 'files:admin']]);

        self::coordinator($http, $user, defaultScopes: ['files:read'])->reauthorize(null, null, new NullCancellation());

        self::assertSame(['files:read'], $user->readRequestedScopes());
    }

    public function testTheDeclaredScopesAreRequestedWhenTheResourceAdvertisesNone(): void
    {
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(), $user, defaultScopes: ['files:read'])->reauthorize(null, null, new NullCancellation());

        self::assertSame(['files:read'], $user->readRequestedScopes());
    }

    public function testAChallengeOutranksTheDeclaredScopes(): void
    {
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(), $user, defaultScopes: ['files:read'])->reauthorize(
            null,
            new WwwAuthenticateChallenge('Bearer', ['scope' => 'files:write']),
            new NullCancellation(),
        );

        self::assertSame(['files:write'], $user->readRequestedScopes());
    }

    public function testAdditionalScopesAreAccumulatedOntoTheSelection(): void
    {
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(['scopes_supported' => ['files:read']]), $user)->upgradeScopes(null, new ScopeSet(['files:write']), null, new NullCancellation());

        self::assertSame(['files:read', 'files:write'], $user->readRequestedScopes());
    }

    public function testAlreadyGrantedScopesSurviveAStepUp(): void
    {
        $store = new InMemoryTokenStore();
        $refused = new AccessToken('the-old-token', self::ISSUER, scopes: ['files:read']);
        $store->write(self::RESOURCE, $refused);
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(), $user, $store)->reauthorize(
            $refused,
            new WwwAuthenticateChallenge('Bearer', ['scope' => 'files:write']),
            new NullCancellation(),
        );

        self::assertSame(['files:write', 'files:read'], $user->readRequestedScopes());
    }

    public function testOfflineAccessIsRequestedWhenItIsAskedForAndTheServerOffersIt(): void
    {
        $user = new ScriptedUserAuthorization();
        $http = self::scriptFullFlow(serverOverrides: ['scopes_supported' => ['files:read', 'offline_access']]);

        self::coordinator($http, $user, offlineAccess: true)->reauthorize(null, null, new NullCancellation());

        self::assertSame(['offline_access'], $user->readRequestedScopes());
    }

    public function testOfflineAccessIsNotRequestedWhenTheServerDoesNotOfferIt(): void
    {
        $user = new ScriptedUserAuthorization();
        $http = self::scriptFullFlow(serverOverrides: ['scopes_supported' => ['files:read']]);

        self::coordinator($http, $user, offlineAccess: true)->reauthorize(null, null, new NullCancellation());

        self::assertSame([], $user->readRequestedScopes());
    }

    public function testOfflineAccessIsNotRequestedUnlessItIsAskedFor(): void
    {
        $user = new ScriptedUserAuthorization();
        $http = self::scriptFullFlow(serverOverrides: ['scopes_supported' => ['files:read', 'offline_access']]);

        self::coordinator($http, $user)->reauthorize(null, null, new NullCancellation());

        self::assertSame([], $user->readRequestedScopes());
    }

    public function testAFailedResponseValidationAbortsBeforeTheTokenExchange(): void
    {
        $http = self::scriptFullFlow();
        $user = new ScriptedUserAuthorization(['error' => 'access_denied', 'iss' => 'https://attacker.example']);

        $this->expectException(InvalidAuthorizationResponseException::class);

        self::coordinator($http, $user)->reauthorize(null, null, new NullCancellation());
    }

    public function testAuthorizeLogsTheServerItAuthorizedAgainst(): void
    {
        $logger = new ArrayLogger();

        self::coordinator(self::scriptFullFlow(), new ScriptedUserAuthorization(), logger: $logger)->reauthorize(null, null, new NullCancellation());

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
        $coordinator->reauthorize(null, null, new NullCancellation());

        $coordinator->fetchToken(new NullCancellation());

        $message = 'The token for {resource} could not be renewed, so a new authorization is needed. {reason}';
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
        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertNull($coordinator->fetchToken(new NullCancellation()));
    }

    public function testATokenLapsingBeyondTheLeewayIsStillUsable(): void
    {
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator(self::scriptFullFlow(tokenOverrides: ['expires_in' => 90]), new ScriptedUserAuthorization(), $store);
        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertSame('the-access-token', $coordinator->fetchToken(new NullCancellation())?->value);
    }

    public function testFetchTokenReturnsNullBeforeAnyAuthorization(): void
    {
        self::assertNull(self::coordinator(new RecordingHttpClient(), new ScriptedUserAuthorization())->fetchToken(new NullCancellation()));
    }

    public function testAStoredTokenIsCheckedAgainstTheResourceBeforeItIsPresented(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('from-an-earlier-run', self::ISSUER));
        $http = self::scriptDiscoveryOnly();

        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);

        self::assertSame('from-an-earlier-run', $coordinator->fetchToken(new NullCancellation())?->value);
        self::assertCount(2, $http->requests);
    }

    public function testAChallengeSteersTheRecoveryWhenAStoredTokenCouldNotBeChecked(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('from-an-earlier-run', self::ISSUER));
        $http = (new RecordingHttpClient())
            // The well-known probes miss, so the stored token cannot be checked and is not presented.
            ->willAnswerJson([], 404)
            ->willAnswerJson([], 404)
            // Only the URL the challenge advertises serves the document.
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-registered-client'])
            ->willAnswerJson(['access_token' => 'the-new-token', 'token_type' => 'Bearer'])
        ;
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);

        self::assertNull($coordinator->fetchToken(new NullCancellation()));

        $token = $coordinator->reauthorize(
            null,
            new WwwAuthenticateChallenge('Bearer', ['resource_metadata' => 'https://mcp.example.com/custom/prm']),
            new NullCancellation(),
        );

        // Holding an unchecked token must not swallow the challenge that says where to check it.
        self::assertSame('the-new-token', $token->value);
        self::assertSame('https://mcp.example.com/custom/prm', (string) $http->readRequest(2)->getUri());
    }

    public function testAStoredTokenIsCheckedOnlyOnce(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('from-an-earlier-run', self::ISSUER));
        $http = self::scriptDiscoveryOnly();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);
        $coordinator->fetchToken(new NullCancellation());

        self::assertSame('from-an-earlier-run', $coordinator->fetchToken(new NullCancellation())?->value);
        self::assertCount(2, $http->requests);
    }

    public function testASpentTokenFromAServerTheResourceLeftIsDroppedRatherThanRenewed(): void
    {
        $store = self::storeHoldingATokenFromAFormerServer();
        $http = self::scriptDiscoveryOnly();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);

        self::assertNull($coordinator->fetchToken(new NullCancellation()));
        self::assertNull($store->read(self::RESOURCE));
        self::assertCount(2, $http->requests);
    }

    public function testATokenFromAServerTheResourceLeftIsLoggedAsSuch(): void
    {
        $logger = new ArrayLogger();

        self::coordinator(
            self::scriptDiscoveryOnly(),
            new ScriptedUserAuthorization(),
            self::storeHoldingATokenFromAFormerServer(),
            $logger,
        )->fetchToken(new NullCancellation());

        $message = 'The token for {resource} was issued by {issuer}, which no longer serves it.';
        self::assertSame(
            [['level' => LogLevel::INFO, 'message' => $message, 'context' => [
                'resource' => self::RESOURCE,
                'issuer' => self::FORMER_ISSUER,
            ]]],
            $logger->recordsMatching(LogLevel::INFO, $message),
        );
    }

    public function testFetchTokenReturnsTheStoredToken(): void
    {
        $coordinator = self::coordinator(self::scriptFullFlow(), new ScriptedUserAuthorization());
        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertSame('the-access-token', $coordinator->fetchToken(new NullCancellation())?->value);
    }

    public function testFetchTokenKeepsAnUnexpiredToken(): void
    {
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator(self::scriptFullFlow(tokenOverrides: ['expires_in' => 3_600]), new ScriptedUserAuthorization(), $store);
        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertSame('the-access-token', $coordinator->fetchToken(new NullCancellation())?->value);
    }

    public function testFetchTokenRenewsASpentTokenWithItsRefreshToken(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['access_token' => 'the-renewed-token', 'token_type' => 'Bearer'])
        ;
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);
        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertSame('the-renewed-token', $coordinator->fetchToken(new NullCancellation())?->value);
        self::assertSame('the-renewed-token', $store->read(self::RESOURCE)?->value);
    }

    public function testASecondRenewalRedeemsARefreshTokenTheServerDidNotRotate(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['access_token' => 'the-renewed-token', 'token_type' => 'Bearer', 'expires_in' => 1])
            ->willAnswerJson(['access_token' => 'the-re-renewed-token', 'token_type' => 'Bearer'])
        ;
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());
        $coordinator->reauthorize(null, null, new NullCancellation());
        $coordinator->fetchToken(new NullCancellation());

        self::assertSame('the-re-renewed-token', $coordinator->fetchToken(new NullCancellation())?->value);
    }

    public function testFetchTokenDropsASpentTokenThatCannotBeRenewed(): void
    {
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator(self::scriptFullFlow(tokenOverrides: ['expires_in' => 1]), new ScriptedUserAuthorization(), $store);
        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertNull($coordinator->fetchToken(new NullCancellation()));
        self::assertNull($store->read(self::RESOURCE));
    }

    public function testFetchTokenDropsATokenWhoseRefreshIsRefused(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['error' => 'invalid_grant'], 400)
        ;
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);
        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertNull($coordinator->fetchToken(new NullCancellation()));
        self::assertNull($store->read(self::RESOURCE));
    }

    public function testConcurrentAuthorizationsRunOneFlowAndPromptOnce(): void
    {
        $gate = new DeferredFuture();
        $http = (new RecordingHttpClient())
            ->willAnswerJson(self::resourceDocument(), gate: $gate->getFuture())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-registered-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
        ;
        $user = new ScriptedUserAuthorization();
        $coordinator = self::coordinator($http, $user);

        $first = async(static fn(): AccessToken => $coordinator->reauthorize(null, null, new NullCancellation()));
        $second = async(static fn(): AccessToken => $coordinator->reauthorize(null, null, new NullCancellation()));
        delay(0);
        $gate->complete();

        self::assertSame($first->await(), $second->await());
        self::assertCount(1, $user->redirects);
        self::assertCount(4, $http->requests);
    }

    public function testAQueuedCallerGivesUpAtItsOwnDeadlineRatherThanTheFlowAheadOfIt(): void
    {
        $gate = new DeferredFuture();
        $http = (new RecordingHttpClient())
            ->willAnswerJson(self::resourceDocument(), gate: $gate->getFuture())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-registered-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
        ;
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());

        /** @var Future<AccessToken> $holder */
        $holder = async(static fn(): AccessToken => $coordinator->reauthorize(null, null, new NullCancellation()));
        delay(0);
        $queued = async(static fn(): AccessToken => $coordinator->reauthorize(null, null, new TimeoutCancellation(0.01)));
        // The fixture holds no I/O of its own, so a referenced timer past the deadline is what lets the
        // loop reach it.
        delay(0.05);

        $refusal = null;

        try {
            $queued->await();
        } catch (CancelledException $e) {
            $refusal = $e;
        }

        self::assertInstanceOf(CancelledException::class, $refusal);

        $gate->complete();

        self::assertSame('the-access-token', $holder->await()->value);
    }

    public function testACallerThatGaveUpWaitingStillHandsTheLockToTheNextInLine(): void
    {
        $gate = new DeferredFuture();
        $http = (new RecordingHttpClient())
            ->willAnswerJson(self::resourceDocument(), gate: $gate->getFuture())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-registered-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
        ;
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());

        /** @var Future<AccessToken> $holder */
        $holder = async(static fn(): AccessToken => $coordinator->reauthorize(null, null, new NullCancellation()));
        delay(0);
        $abandoned = async(static fn(): AccessToken => $coordinator->reauthorize(null, null, new TimeoutCancellation(0.01)));
        delay(0.05);
        $abandoned->ignore();
        // Queued behind a caller that has already given up, so it only ever runs if the lock that one is
        // still owed comes free.
        $served = null;
        $behind = async(static function () use ($coordinator, &$served): void {
            $served = $coordinator->reauthorize(null, null, new NullCancellation())->value;
        });
        $behind->ignore();
        delay(0);
        $gate->complete();
        // Reached as a plain value rather than an await, so a lock that never came free reads as a caller
        // still waiting instead of taking the loop down with it.
        delay(0.05);

        self::assertSame('the-access-token', $holder->await()->value);
        self::assertSame('the-access-token', $served);
    }

    public function testTheReEntryEscapeLastsOnlyAsLongAsTheCallThatTookTheLock(): void
    {
        $gate = new DeferredFuture();
        $http = self::scriptFullFlow(tokenOverrides: ['access_token' => 'the-first-token'])
            ->willAnswerJson(self::resourceDocument(), gate: $gate->getFuture())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['access_token' => 'the-third-token', 'token_type' => 'Bearer'])
        ;
        $user = new ScriptedUserAuthorization();
        $coordinator = self::coordinator($http, $user);

        // Both of these run in the main context, so they share the fiber whose re-entry escape the second
        // one must not inherit from the first.
        $first = $coordinator->reauthorize(null, null, new NullCancellation());

        /** @var Future<AccessToken> $holder */
        $holder = async(static fn(): AccessToken => $coordinator->reauthorize($first, null, new NullCancellation()));
        delay(0);
        EventLoop::delay(0.02, static fn() => $gate->complete());

        $second = $coordinator->reauthorize($first, null, new NullCancellation());

        self::assertSame('the-second-token', $holder->await()->value);
        self::assertSame('the-second-token', $second->value);
        self::assertCount(2, $user->redirects);
    }

    public function testAUserAuthorizationThatReEntersTheCoordinatorDoesNotWaitOnItsOwnLock(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('the-spent-token', self::ISSUER, expiresAt: time() - 1));
        $user = new class implements UserAuthorizationInterface {
            public ?AuthorizationCoordinator $coordinator = null;
            public bool $reEntered = false;
            public ?AccessToken $reEntrantToken = null;

            #[\Override]
            public function authorize(AuthorizationRedirect $redirect, Cancellation $cancellation): AuthorizationCallback
            {
                $this->reEntered = true;
                $this->reEntrantToken = $this->coordinator?->fetchToken($cancellation);

                return new AuthorizationCallback('http://localhost:3000/callback?'.http_build_query([
                    'state' => $redirect->state,
                    'code' => 'the-code',
                ]));
            }
        };
        $coordinator = self::coordinator(self::scriptFullFlow(), $user, $store);
        $user->coordinator = $coordinator;

        $token = $coordinator->upgradeScopes(
            $store->read(self::RESOURCE),
            new ScopeSet(['files:write']),
            null,
            new NullCancellation(),
        );

        self::assertTrue($user->reEntered);
        self::assertNull($user->reEntrantToken);
        self::assertSame('the-access-token', $token->value);
    }

    public function testConcurrentRenewalsRedeemTheRefreshTokenOnce(): void
    {
        $gate = new DeferredFuture();
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['access_token' => 'the-renewed-token', 'token_type' => 'Bearer'], gate: $gate->getFuture())
        ;
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);
        $coordinator->reauthorize(null, null, new NullCancellation());

        $first = async(static fn(): ?AccessToken => $coordinator->fetchToken(new NullCancellation()));
        $second = async(static fn(): ?AccessToken => $coordinator->fetchToken(new NullCancellation()));
        delay(0);
        $gate->complete();

        $firstToken = $first->await();
        $secondToken = $second->await();
        $renewed = $store->read(self::RESOURCE);

        self::assertSame('the-renewed-token', $renewed?->value);
        self::assertSame($renewed, $firstToken);
        self::assertSame($renewed, $secondToken);
        self::assertCount(5, $http->requests);
    }

    public function testATokenAnotherCallerRenewedIsStillCheckedBeforeItIsHandedOn(): void
    {
        $gate = new DeferredFuture();
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            // The renewal the first caller drives lands already spent, so handing it to the second caller
            // unchecked would present a token that lapsed before it was ever sent.
            ->willAnswerJson(['access_token' => 'the-spent-renewal', 'token_type' => 'Bearer', 'expires_in' => 1], gate: $gate->getFuture())
            ->willAnswerJson(['access_token' => 'the-usable-renewal', 'token_type' => 'Bearer'])
        ;
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());
        $coordinator->reauthorize(null, null, new NullCancellation());

        $first = async(static fn(): ?AccessToken => $coordinator->fetchToken(new NullCancellation()));
        $second = async(static fn(): ?AccessToken => $coordinator->fetchToken(new NullCancellation()));
        delay(0);
        $gate->complete();

        $firstToken = $first->await();
        $secondToken = $second->await();

        if (! $firstToken instanceof AccessToken || ! $secondToken instanceof AccessToken) {
            self::fail('Both callers should have been handed a token.');
        }

        self::assertSame('the-spent-renewal', $firstToken->value);
        self::assertSame('the-usable-renewal', $secondToken->value);
    }

    public function testATokenAnotherProcessWroteIsCheckedAgainstTheIssuerDiscoveryFound(): void
    {
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator(self::scriptDiscoveryOnly(), new ScriptedUserAuthorization(), $store);
        $store->write(self::RESOURCE, new AccessToken('from-this-process', self::ISSUER));
        $coordinator->fetchToken(new NullCancellation());

        // A store shared with another process can be written behind this coordinator's back, long after
        // discovery settled what the resource is protected by.
        $store->write(self::RESOURCE, new AccessToken('from-another-process', self::FORMER_ISSUER));

        self::assertNull($coordinator->fetchToken(new NullCancellation()));
        self::assertNull($store->read(self::RESOURCE));
    }

    public function testAStepUpDoesNotStandDownForATokenDiscoveryHasNeverChecked(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('from-an-earlier-run', self::ISSUER, scopes: ['files:read', 'files:write']));
        $coordinator = self::coordinator(self::scriptFullFlow(), new ScriptedUserAuthorization(), $store);

        // The held token names the challenged scope, but nothing has confirmed it is even for this
        // resource, so it cannot stand in for the grant the step-up asks for.
        $token = $coordinator->upgradeScopes(null, new ScopeSet(['files:write']), null, new NullCancellation());

        self::assertSame('the-access-token', $token->value);
    }

    public function testReadGrantedScopesReportsWhatTheStoredTokenCarries(): void
    {
        $http = self::scriptFullFlow(['scopes_supported' => ['files:read', 'files:write']]);
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());
        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertSame(['files:read', 'files:write'], $coordinator->readGrantedScopes()->values);
    }

    public function testReadGrantedScopesIsEmptyBeforeAnyAuthorization(): void
    {
        $coordinator = self::coordinator(new RecordingHttpClient(), new ScriptedUserAuthorization());

        self::assertSame([], $coordinator->readGrantedScopes()->values);
    }

    public function testARefreshFailureThatIsNotAGrantRejectionSurfacesAndKeepsTheToken(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['error' => 'server_error'], 500)
        ;
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);
        $coordinator->reauthorize(null, null, new NullCancellation());

        try {
            $coordinator->fetchToken(new NullCancellation());
            self::fail('The refusal should have surfaced.');
        } catch (TokenRequestFailedException $e) {
            self::assertSame('The token request failed with "server_error".', $e->getMessage());
        }

        self::assertSame('the-access-token', $store->read(self::RESOURCE)?->value);
    }

    public function testARenewalRefusedForAnUnknownClientDropsTheRegistration(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson(['error' => 'invalid_client'], 401)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-second-client'])
            ->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer'])
        ;
        $registrations = new InMemoryClientRegistrationStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), registrations: $registrations);
        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertNull($coordinator->fetchToken(new NullCancellation()));

        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertSame('https://auth.example.com/register', (string) $http->readRequest(7)->getUri());
        self::assertSame('the-second-client', $registrations->read(self::ISSUER)?->clientId);
    }

    public function testACodeExchangeRefusedForAnUnknownClientDropsTheRegistrationAndSurfaces(): void
    {
        $http = self::scriptFullFlow()->willAnswerJson(['error' => 'invalid_client'], 401);
        $registrations = new InMemoryClientRegistrationStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), registrations: $registrations);
        $coordinator->reauthorize(null, null, new NullCancellation());

        try {
            $coordinator->upgradeScopes(null, new ScopeSet(['files:admin']), null, new NullCancellation());
            self::fail('The refusal should have surfaced.');
        } catch (ClientRegistrationRejectedException $e) {
            self::assertSame(
                'The authorization server does not recognise the client identifier presented to it, so the client must register again.',
                $e->getMessage(),
            );
        }

        self::assertNull($registrations->read(self::ISSUER));
    }

    public function testASpentTokenThatCannotBeRenewedStillLeavesItsScopesToTheNextGrant(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'scope' => 'files:read'])
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer'])
        ;
        $user = new ScriptedUserAuthorization();
        $coordinator = self::coordinator($http, $user);
        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertNull($coordinator->fetchToken(new NullCancellation()));

        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertSame(['files:read'], $user->readRequestedScopes(1));
    }

    public function testRenewalFindingNoMetadataYieldsRatherThanThrowing(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('from-an-earlier-run', self::ISSUER, time() + 1, 'the-refresh-token'));
        $http = (new RecordingHttpClient())->willAnswerJson([], 404)->willAnswerJson([], 404);
        $logger = new ArrayLogger();

        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store, $logger);

        self::assertNull($coordinator->fetchToken(new NullCancellation()));

        $message = 'The token for {resource} could not be checked against any metadata. {reason}';
        self::assertSame(
            [['level' => LogLevel::INFO, 'message' => $message, 'context' => [
                'resource' => self::RESOURCE,
                'reason' => 'No protected resource metadata was served for "https://mcp.example.com/mcp". Probed: '
                    .'https://mcp.example.com/.well-known/oauth-protected-resource/mcp, '
                    .'https://mcp.example.com/.well-known/oauth-protected-resource.',
            ]]],
            $logger->recordsMatching(LogLevel::INFO, $message),
        );
    }

    public function testTwoResourcesAuthorizingAtOnceDoNotWaitOnEachOther(): void
    {
        $gate = new DeferredFuture();
        $other = 'https://other.example.com/mcp';
        $theirHttp = (new RecordingHttpClient())
            // Theirs parks on its own metadata, which must not hold ours up.
            ->willAnswerJson(['resource' => $other, 'authorization_servers' => [self::ISSUER]], gate: $gate->getFuture())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-registered-client'])
            ->willAnswerJson(['access_token' => 'the-other-token', 'token_type' => 'Bearer'])
        ;
        $store = new InMemoryTokenStore();
        $user = new ScriptedUserAuthorization();
        $theirs = self::coordinator($theirHttp, $user, $store, resource: $other);
        $ours = self::coordinator(self::scriptFullFlow(), $user, $store);

        $first = async(static fn(): AccessToken => $theirs->reauthorize(null, null, new NullCancellation()));
        delay(0);
        $second = async(static fn(): AccessToken => $ours->reauthorize(null, null, new NullCancellation()));

        $second->await();

        self::assertSame('the-access-token', $store->read(self::RESOURCE)?->value);
        self::assertCount(1, $theirHttp->requests);

        $gate->complete();
        $first->await();

        self::assertSame('the-other-token', $store->read($other)?->value);
    }

    public function testAChallengeNamingAnEmptyScopeFallsBackToTheDeclaredScopes(): void
    {
        $user = new ScriptedUserAuthorization();

        self::coordinator(self::scriptFullFlow(), $user, defaultScopes: ['files:read'])->reauthorize(
            null,
            new WwwAuthenticateChallenge('Bearer', ['scope' => '']),
            new NullCancellation(),
        );

        self::assertSame(['files:read'], $user->readRequestedScopes());
    }

    public function testOfflineAccessAdvertisedByTheResourceIsNotRequestedWithoutTheOptIn(): void
    {
        $user = new ScriptedUserAuthorization();
        $http = self::scriptFullFlow(['scopes_supported' => ['files:read', 'offline_access']]);

        self::coordinator($http, $user)->reauthorize(null, null, new NullCancellation());

        self::assertSame(['files:read'], $user->readRequestedScopes());
    }

    public function testAUsableTokenIsHandedOutWhileAnotherCallerIsStillAuthorizing(): void
    {
        $gate = new DeferredFuture();
        $http = self::scriptFullFlow()->willAnswerJson(
            ['access_token' => 'the-wide-token', 'token_type' => 'Bearer'],
            gate: $gate->getFuture(),
        );
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());
        $presented = $coordinator->reauthorize(null, null, new NullCancellation());

        $order = [];
        $upgrade = async(static function () use ($coordinator, $presented, &$order): void {
            $coordinator->upgradeScopes($presented, new ScopeSet(['files:admin']), null, new NullCancellation());
            $order[] = 'upgrade';
        });
        delay(0);
        $fetch = async(static function () use ($coordinator, &$order): void {
            $coordinator->fetchToken(new NullCancellation());
            $order[] = 'fetch';
        });
        delay(0);
        $gate->complete();
        $upgrade->await();
        $fetch->await();

        // The step-up holds the lock until the gate opens, and a token that is already usable must not queue
        // behind it.
        self::assertSame(['fetch', 'upgrade'], $order);
    }

    public function testAStepUpJoinerReachingPastTheRunningGrantAsksForItsOwn(): void
    {
        $gate = new DeferredFuture();
        $http = self::scriptFullFlow()
            ->willAnswerJson(['access_token' => 'the-narrow-token', 'token_type' => 'Bearer'], gate: $gate->getFuture())
            ->willAnswerJson(['access_token' => 'the-wide-token', 'token_type' => 'Bearer'])
        ;
        $user = new ScriptedUserAuthorization();
        $coordinator = self::coordinator($http, $user);

        // Warming discovery and the registration leaves the token endpoint as the only leg still to run.
        $presented = $coordinator->reauthorize(null, null, new NullCancellation());

        $first = async(static fn(): AccessToken => $coordinator->upgradeScopes($presented, new ScopeSet(['files:read']), null, new NullCancellation()));
        $second = async(static fn(): AccessToken => $coordinator->upgradeScopes($presented, new ScopeSet(['files:admin']), null, new NullCancellation()));
        delay(0);
        $gate->complete();

        $first->await();
        $second->await();

        self::assertCount(3, $user->redirects);
        self::assertSame(['files:admin', 'files:read'], $user->readRequestedScopes(2));
    }

    public function testAStepUpJoinerTheRunningGrantAlreadyCoversTakesItsToken(): void
    {
        $gate = new DeferredFuture();
        $http = self::scriptFullFlow()
            ->willAnswerJson(
                ['access_token' => 'the-wide-token', 'token_type' => 'Bearer', 'scope' => 'files:admin'],
                gate: $gate->getFuture(),
            )
        ;
        $user = new ScriptedUserAuthorization();
        $coordinator = self::coordinator($http, $user);
        $presented = $coordinator->reauthorize(null, null, new NullCancellation());

        $first = async(static fn(): AccessToken => $coordinator->upgradeScopes($presented, new ScopeSet(['files:admin']), null, new NullCancellation()));
        $second = async(static fn(): AccessToken => $coordinator->upgradeScopes($presented, new ScopeSet(['files:admin']), null, new NullCancellation()));
        delay(0);
        $gate->complete();

        self::assertSame($first->await(), $second->await());
        self::assertCount(2, $user->redirects);
    }

    public function testReauthorizingDropsTheRefusedTokenButRemembersWhatItGranted(): void
    {
        $store = new InMemoryTokenStore();
        $http = self::scriptFullFlow(['scopes_supported' => ['files:read', 'files:write']])
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer'])
        ;
        $user = new ScriptedUserAuthorization();
        $coordinator = self::coordinator($http, $user, $store);
        $refused = $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertSame('the-second-token', $coordinator->reauthorize($refused, null, new NullCancellation())->value);
        self::assertSame(['files:read', 'files:write'], $user->readRequestedScopes(1));
    }

    public function testAGrantThatOmitsAScopeGrantedEarlierStopsAskingForIt(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['scope' => 'files:read files:write'])
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer', 'scope' => 'files:read'])
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['access_token' => 'the-third-token', 'token_type' => 'Bearer', 'scope' => 'files:read'])
        ;
        $user = new ScriptedUserAuthorization();
        $coordinator = self::coordinator($http, $user);

        $first = $coordinator->reauthorize(null, null, new NullCancellation());
        $second = $coordinator->reauthorize($first, null, new NullCancellation());
        $coordinator->reauthorize($second, null, new NullCancellation());

        self::assertSame(['files:read', 'files:write'], $user->readRequestedScopes(1));
        self::assertSame(['files:read'], $user->readRequestedScopes(2));
    }

    public function testReauthorizingReachesAStoreNoAuthorizationInThisProcessFilled(): void
    {
        $store = new InMemoryTokenStore();
        $refused = new AccessToken('from-an-earlier-run', self::ISSUER);
        $store->write(self::RESOURCE, $refused);

        self::coordinator(self::scriptFullFlow(), new ScriptedUserAuthorization(), $store)->reauthorize($refused, null, new NullCancellation());

        self::assertSame('the-access-token', $store->read(self::RESOURCE)?->value);
    }

    public function testReauthorizingReadsTheMetadataAfresh(): void
    {
        $http = self::scriptFullFlow()
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer'])
        ;
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());
        $refused = $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertSame('the-second-token', $coordinator->reauthorize($refused, null, new NullCancellation())->value);
        self::assertCount(7, $http->requests);
    }

    public function testAStepUpReusesWhatDiscoveryAlreadyFound(): void
    {
        $http = self::scriptFullFlow()->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer']);
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization());
        $presented = $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertSame(
            'the-second-token',
            $coordinator->upgradeScopes($presented, new ScopeSet(['files:admin']), null, new NullCancellation())->value,
        );
        self::assertCount(5, $http->requests);
    }

    public function testARenewalAGatewayBrokeSurfacesAndKeepsTheRefreshToken(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson('<html>Bad Gateway</html>', 502)
        ;
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);
        $coordinator->reauthorize(null, null, new NullCancellation());

        try {
            $coordinator->fetchToken(new NullCancellation());
            self::fail('The gateway failure should have surfaced.');
        } catch (TokenRequestFailedException $e) {
            self::assertSame('The token request failed with "invalid_request": The token endpoint answered 502 with a body that is not a JSON object.', $e->getMessage());
        }

        // The gateway said nothing about the grant, so the refresh token it never reached is still good.
        self::assertSame('the-refresh-token', $store->read(self::RESOURCE)?->refreshToken);
    }

    public function testARenewalAnsweredWithSomethingOtherThanJsonYieldsRatherThanThrowing(): void
    {
        $http = self::scriptFullFlow(tokenOverrides: ['expires_in' => 1, 'refresh_token' => 'the-refresh-token'])
            ->willAnswerJson('<html>All good, honest</html>')
        ;
        $store = new InMemoryTokenStore();
        $coordinator = self::coordinator($http, new ScriptedUserAuthorization(), $store);
        $coordinator->reauthorize(null, null, new NullCancellation());

        self::assertNull($coordinator->fetchToken(new NullCancellation()));
        self::assertNull($store->read(self::RESOURCE));
    }

    public function testAnExpiredTokenWithoutARefreshTokenIsRenewedByAFreshUnattendedGrant(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('the-spent-token', self::ISSUER, time() + 1, null, ['files:read']));
        $http = self::scriptDiscoveryOnly();
        $strategy = new ScriptedGrantStrategy(true, new AccessToken('the-fresh-token', self::ISSUER, null, null, ['files:read']));

        $token = self::coordinator($http, $strategy, $store)->fetchToken(new NullCancellation());

        self::assertNotNull($token);
        self::assertSame('the-fresh-token', $token->value);
        self::assertSame('the-fresh-token', $store->read(self::RESOURCE)?->value);
        // What the spent token held is re-asked for rather than narrowed away.
        self::assertSame(['files:read'], $strategy->readContext()->scopes->values);
    }

    public function testTheRenewalGrantReusesTheDiscoveryThatCheckedTheSpentToken(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('the-spent-token', self::ISSUER, time() + 1));
        $http = self::scriptDiscoveryOnly();
        $strategy = new ScriptedGrantStrategy(true, new AccessToken('the-fresh-token', self::ISSUER));

        self::coordinator($http, $strategy, $store)->fetchToken(new NullCancellation());

        // Discovering again would repeat both documents, and without a challenge it could not reach a
        // resource document that only a challenge names.
        self::assertSame([
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
            'https://auth.example.com/.well-known/oauth-authorization-server',
        ], array_map(static fn($request): string => (string) $request->getUri(), $http->requests));
    }

    public function testAnUnattendedStrategyRerunsItsGrantRatherThanRedeemingARefreshToken(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('the-spent-token', self::ISSUER, time() + 1, 'the-refresh-token'));
        $http = self::scriptDiscoveryOnly();
        $strategy = new ScriptedGrantStrategy(true, new AccessToken('the-fresh-token', self::ISSUER));

        $token = self::coordinator($http, $strategy, $store)->fetchToken(new NullCancellation());

        // The registration a refresh resolves is not the one an unattended strategy authenticates with, so
        // the refresh token is left unredeemed and nothing reaches the token endpoint.
        self::assertSame('the-fresh-token', $token?->value);
        self::assertCount(1, $strategy->contexts);
        self::assertCount(2, $http->requests);
    }

    public function testAFailedUnattendedRenewalPropagatesRatherThanSendingTheRequestBare(): void
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken('the-spent-token', self::ISSUER, time() + 1));
        $http = self::scriptDiscoveryOnly();

        try {
            self::coordinator($http, new ScriptedGrantStrategy(true), $store)->fetchToken(new NullCancellation());
            self::fail('The renewal should have propagated.');
        } catch (\OutOfBoundsException $e) {
            self::assertSame('No token was scripted for this grant.', $e->getMessage());
            // The spent token is dropped before the grant runs, so a failed renewal leaves nothing
            // presentable behind.
            self::assertNull($store->read(self::RESOURCE));
        }
    }

    private static function storeHoldingATokenFromAFormerServer(): InMemoryTokenStore
    {
        $store = new InMemoryTokenStore();
        $store->write(self::RESOURCE, new AccessToken(
            'from-the-server-it-left',
            self::FORMER_ISSUER,
            time() + 1,
            'the-refresh-token',
        ));

        return $store;
    }

    private static function scriptDiscoveryOnly(): RecordingHttpClient
    {
        return (new RecordingHttpClient())
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
        ;
    }

    /**
     * @param list<non-empty-string> $defaultScopes
     */
    private static function coordinator(
        RecordingHttpClient $http,
        GrantStrategyInterface|UserAuthorizationInterface $user,
        ?InMemoryTokenStore $tokens = null,
        ?ArrayLogger $logger = null,
        ?InMemoryClientRegistrationStore $registrations = null,
        bool $offlineAccess = false,
        array $defaultScopes = [],
        string $resource = self::RESOURCE,
    ): AuthorizationCoordinator {
        return new AuthorizationCoordinator(
            new ResourceIdentifier($resource),
            new MetadataDiscovery($http),
            new ClientRegistrar($http, $registrations ?? new InMemoryClientRegistrationStore()),
            new TokenEndpoint($http),
            $http,
            $user instanceof GrantStrategyInterface ? $user : new AuthorizationCodeGrantStrategy($user),
            $tokens ?? new InMemoryTokenStore(),
            new AuthorizationOptions(
                'Example MCP Client',
                'http://localhost:3000/callback',
                requestOfflineAccess: $offlineAccess,
                defaultScopes: $defaultScopes,
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
        return (new RecordingHttpClient())
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
