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

use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\NullCancellation;
use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizedHttpClient;
use Nexus\Mcp\Client\Auth\InMemoryTokenStore;
use Nexus\Mcp\Client\Auth\InsufficientScopePolicy;
use Nexus\Mcp\Client\Exception\InsufficientScopeException;
use Nexus\Mcp\Client\Exception\RedirectRefusedException;
use Nexus\Mcp\Tests\Fixtures\Client\Auth\ScriptedUserAuthorization;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

use function Amp\async;

/**
 * @internal
 */
#[CoversClass(AuthorizedHttpClient::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizedHttpClientTest extends TestCase
{
    private const string RESOURCE = 'https://127.0.0.1:1/mcp';
    private const string CHALLENGE = 'Bearer resource_metadata="https://127.0.0.1:1/.well-known/oauth-protected-resource/mcp"';

    public function testAnUnprotectedServerIsCalledWithNoAuthorization(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(['ok' => true]);

        $response = self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertCount(1, $http->requests);
        self::assertNull($http->readRequest()->getHeader('Authorization'));
    }

    public function testAChallengeTriggersTheFlowAndTheRequestIsRetriedWithTheToken(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;

        $response = self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertCount(6, $http->requests);
        self::assertNull($http->readRequest(0)->getHeader('Authorization'));
        self::assertSame('Bearer the-access-token', $http->readRequest(5)->getHeader('Authorization'));
    }

    public function testEveryLegOfTheFlowIsBoundedByTheRequestsOwnCancellation(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();
        $cancellation = new NullCancellation();

        self::client($http, $user)->request(self::mcpRequest(), $cancellation);

        self::assertSame(array_fill(0, 6, $cancellation), $http->cancellations);
        self::assertSame([$cancellation], $user->cancellations);
    }

    public function testATokenIsNeverSentToAnotherOrigin(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
            ->willAnswerJson(['ok' => true])
        ;
        $client = self::client($http);
        $client->request(self::mcpRequest(), new NullCancellation());

        $client->request(new Request('https://attacker.example.com/mcp', 'POST', '{}'), new NullCancellation());

        self::assertSame('Bearer the-access-token', $http->readRequest(5)->getHeader('Authorization'));
        self::assertNull($http->readRequest(6)->getHeader('Authorization'));
    }

    public function testATokenThatFollowedARedirectOffTheMcpServerIsReported(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerFrom('http://127.0.0.1:1/mcp')
        ;

        $this->expectException(RedirectRefusedException::class);
        $this->expectExceptionMessageIs('The request to "https://127.0.0.1:1/mcp" was answered from "http://127.0.0.1:1/mcp" after a redirect. Credentials are never carried across one.');

        self::client($http)->request(self::mcpRequest(), new NullCancellation());
    }

    public function testAnUnauthenticatedRequestThatFollowedARedirectIsLeftAlone(): void
    {
        $http = new RecordingHttpClient()->willAnswerFrom('https://elsewhere.example.com/mcp', ['ok' => true]);

        self::assertSame(200, self::client($http)->request(self::mcpRequest(), new NullCancellation())->getStatus());
    }

    public function testTheChallengeSteersDiscoveryToTheAdvertisedUrl(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, 'Bearer resource_metadata="https://127.0.0.1:1/custom/prm"')
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;

        self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame('https://127.0.0.1:1/custom/prm', (string) $http->readRequest(1)->getUri());
    }

    public function testTheChallengeScopeIsRequested(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, 'Bearer resource_metadata="https://127.0.0.1:1/.well-known/oauth-protected-resource/mcp", scope="files:read"')
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();

        self::client($http, $user)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(['files:read'], $user->readRequestedScopes());
    }

    public function testAStoredTokenIsPresentedOnALaterRequest(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
            ->willAnswerJson(['ok' => true])
        ;
        $client = self::client($http);

        $client->request(self::mcpRequest(), new NullCancellation());
        $client->request(self::mcpRequest(), new NullCancellation());

        self::assertCount(7, $http->requests);
        self::assertSame('Bearer the-access-token', $http->readRequest(6)->getHeader('Authorization'));
    }

    public function testASecondUnauthorizedAnswerIsReturnedRatherThanRetriedForever(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willChallenge(401, self::CHALLENGE)
        ;

        $response = self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(401, $response->getStatus());
        self::assertCount(6, $http->requests);
    }

    public function testAnInsufficientScopeChallengeStepsTheScopesUpAndRetries(): void
    {
        // The first round stores the client identifier and what discovery found, so the step-up repeats
        // neither and goes straight back to the token endpoint.
        $http = self::scriptChallengeAndFlow()
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"')
            ->willAnswerJson(['access_token' => 'the-wider-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();

        $response = self::client($http, $user)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertSame(['files:write'], $user->readRequestedScopes(1));
        self::assertCount(8, $http->requests);
        self::assertSame('Bearer the-wider-token', $http->readRequest(7)->getHeader('Authorization'));
        self::assertTrue($http->drainedBodies[5] ?? false);
    }

    public function testAScopeChallengeNamingNothingTheTokenLacksIsReturned(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer', 'scope' => 'files:read'])
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:read"')
        ;
        $user = new ScriptedUserAuthorization();
        $logger = new ArrayLogger();

        $response = self::client($http, $user, logger: $logger)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(403, $response->getStatus());
        self::assertCount(6, $http->requests);
        self::assertCount(1, $user->redirects);
        self::assertSame(
            [['level' => LogLevel::WARNING, 'message' => 'The scope challenge from {resource} names {scopes}.', 'context' => [
                'resource' => self::RESOURCE,
                'scopes' => 'files:read',
            ]]],
            $logger->recordsMatching(LogLevel::WARNING, 'The scope challenge from {resource} names {scopes}.'),
        );
    }

    public function testAScopeChallengeNamingNoScopeAtAllIsReturned(): void
    {
        $http = self::scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="insufficient_scope"');
        $user = new ScriptedUserAuthorization();

        $response = self::client($http, $user)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(403, $response->getStatus());
        self::assertCount(6, $http->requests);
        self::assertCount(1, $user->redirects);
    }

    public function testTheFailPolicyReportsTheChallengedScopesInsteadOfAskingForThem(): void
    {
        $http = self::scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write files:admin"');
        $user = new ScriptedUserAuthorization();

        $this->expectException(InsufficientScopeException::class);
        $this->expectExceptionMessageIs('The MCP server requires the scope "files:write files:admin", which the token does not carry.');

        self::client($http, $user, policy: InsufficientScopePolicy::Fail)->request(self::mcpRequest(), new NullCancellation());
    }

    public function testTheFailPolicyOpensNoSecondConsentScreen(): void
    {
        $http = self::scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"');
        $user = new ScriptedUserAuthorization();

        try {
            self::client($http, $user, policy: InsufficientScopePolicy::Fail)->request(self::mcpRequest(), new NullCancellation());
            self::fail('The insufficient-scope answer should have surfaced.');
        } catch (InsufficientScopeException $e) {
            self::assertSame(['files:write'], $e->required);
        }

        self::assertCount(1, $user->redirects);
        self::assertCount(6, $http->requests);
        self::assertTrue($http->drainedBodies[5] ?? false);
    }

    public function testTheFailPolicyIsNotDefeatedByAnExhaustedUpgradeBudget(): void
    {
        $http = self::scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"');

        $this->expectException(InsufficientScopeException::class);

        self::client($http, maxScopeUpgrades: 0, policy: InsufficientScopePolicy::Fail)
            ->request(self::mcpRequest(), new NullCancellation())
        ;
    }

    public function testTheFailPolicyStillReportsAChallengeNamingNothingNew(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer', 'scope' => 'files:read'])
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:read"')
        ;

        $this->expectException(InsufficientScopeException::class);
        $this->expectExceptionMessageIs('The MCP server requires the scope "files:read", which the token does not carry.');

        self::client($http, policy: InsufficientScopePolicy::Fail)->request(self::mcpRequest(), new NullCancellation());
    }

    public function testALapsedTokenLeavesItsScopesToTheGrantThatReplacesIt(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'token-1', 'token_type' => 'Bearer', 'scope' => 'files:read', 'expires_in' => 1])
            ->willAnswerJson(['ok' => true])
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['access_token' => 'token-2', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();
        $client = self::client($http, $user);

        $client->request(self::mcpRequest(), new NullCancellation());
        $client->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(['files:read'], $user->readRequestedScopes(1));
    }

    public function testConcurrentChallengesOpenOneConsentScreen(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();
        $client = self::client($http, $user);

        $first = async(static fn(): Response => $client->request(self::mcpRequest(), new NullCancellation()));
        $second = async(static fn(): Response => $client->request(self::mcpRequest(), new NullCancellation()));

        $first->await();
        $second->await();

        self::assertCount(1, $user->redirects);
    }

    public function testAForbiddenAnswerThatIsNotAScopeChallengeIsReturned(): void
    {
        $http = self::scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="invalid_token"');

        $response = self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(403, $response->getStatus());
        self::assertCount(6, $http->requests);
    }

    public function testAForbiddenAnswerWithNoChallengeIsReturned(): void
    {
        $http = self::scriptChallengeAndFlow()->willAnswerJson(['error' => 'nope'], 403);

        $response = self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(403, $response->getStatus());
        self::assertCount(6, $http->requests);
    }

    public function testScopeUpgradesAreCappedAndTheChallengeIsReturned(): void
    {
        $http = self::scriptChallengeAndFlow()
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"')
            ->willAnswerJson(['access_token' => 'the-wider-token', 'token_type' => 'Bearer'])
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:admin"')
        ;

        $logger = new ArrayLogger();
        $response = self::client($http, logger: $logger, maxScopeUpgrades: 1)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(403, $response->getStatus());
        self::assertSame(
            [['level' => LogLevel::WARNING, 'message' => 'Giving up on {resource} after {attempts} scope upgrades.', 'context' => [
                'resource' => self::RESOURCE,
                'attempts' => 1,
            ]]],
            $logger->recordsMatching(LogLevel::WARNING, 'Giving up on {resource} after {attempts} scope upgrades.'),
        );
    }

    public function testAnUnauthorizedAnswerWithNoChallengeStillStartsDiscovery(): void
    {
        $http = new RecordingHttpClient()
            ->willAnswerJson([], 401)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;

        $response = self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertSame(
            'https://127.0.0.1:1/.well-known/oauth-protected-resource/mcp',
            (string) $http->readRequest(1)->getUri(),
        );
    }

    public function testARejectedTokenCarriesItsGrantedScopesIntoTheNextOne(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer', 'scope' => 'files:read'])
            ->willAnswerJson(['ok' => true])
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();
        $client = self::client($http, $user);

        $client->request(self::mcpRequest(), new NullCancellation());
        $client->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(['files:read'], $user->readRequestedScopes(1));
    }

    public function testARejectedTokenIsDroppedEvenWhenReauthorizingFails(): void
    {
        $http = self::scriptChallengeAndFlow()
            ->willAnswerJson(['ok' => true])
            ->willChallenge(401, self::CHALLENGE)
            ->willFail(new HttpException('The network is gone.'))
        ;
        $store = new InMemoryTokenStore();
        $client = self::client($http, tokens: $store);
        $client->request(self::mcpRequest(), new NullCancellation());

        try {
            $client->request(self::mcpRequest(), new NullCancellation());
            self::fail('The failed discovery should have surfaced.');
        } catch (HttpException) {
            self::assertNull($store->read(self::RESOURCE));
        }
    }

    public function testAFreshChallengeFollowsTheResourceToAnotherAuthorizationServer(): void
    {
        $http = self::scriptChallengeAndFlow()
            ->willAnswerJson(['ok' => true])
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(['resource' => self::RESOURCE, 'authorization_servers' => ['https://successor.test']])
            ->willAnswerJson([
                'issuer' => 'https://successor.test',
                'authorization_endpoint' => 'https://successor.test/authorize',
                'token_endpoint' => 'https://successor.test/token',
                'registration_endpoint' => 'https://successor.test/register',
                'code_challenge_methods_supported' => ['S256'],
            ])
            ->willAnswerJson(['client_id' => 'the-successor-client'])
            ->willAnswerJson(['access_token' => 'the-successor-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $store = new InMemoryTokenStore();
        $client = self::client($http, tokens: $store);

        $client->request(self::mcpRequest(), new NullCancellation());
        $client->request(self::mcpRequest(), new NullCancellation());

        self::assertSame('https://successor.test', $store->read(self::RESOURCE)?->issuer);
        self::assertSame('https://successor.test/token', (string) $http->readRequest(10)->getUri());
    }

    public function testTheChallengeBodyIsDrainedSoItsConnectionIsReleased(): void
    {
        $http = self::scriptChallengeAndFlow()->willAnswerJson(['ok' => true]);

        self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertTrue($http->drainedBodies[0] ?? false);
    }

    public function testAChallengeBodyAtTheDrainCapIsStillRead(): void
    {
        $http = self::scriptChallengeAndFlow(str_repeat('x', 8192))->willAnswerJson(['ok' => true]);

        $response = self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertTrue($http->drainedBodies[0] ?? false);
    }

    public function testAnOversizedChallengeBodyIsGivenUpOnButStillAuthorizes(): void
    {
        $http = self::scriptChallengeAndFlow(str_repeat('x', 8193))->willAnswerJson(['ok' => true]);

        $response = self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertFalse($http->drainedBodies[0] ?? false);
    }

    public function testTheCallerRequestIsNotMutatedByTheRetry(): void
    {
        $http = self::scriptChallengeAndFlow()->willAnswerJson(['ok' => true]);
        $request = self::mcpRequest();

        self::client($http)->request($request, new NullCancellation());

        self::assertNull($request->getHeader('Authorization'));
    }

    public function testASuppliedTokenStoreIsUsed(): void
    {
        $store = new InMemoryTokenStore();

        self::client(self::scriptChallengeAndFlow()->willAnswerJson(['ok' => true]), tokens: $store)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame('the-access-token', $store->read(self::RESOURCE)?->value);
    }

    public function testAStoredTokenPastItsLifetimeIsRenewedBeforeTheRequestIsSent(): void
    {
        $tokens = new InMemoryTokenStore();
        $tokens->write(self::RESOURCE, new AccessToken('the-stored-token', 'https://auth.test', time() - 1, 'the-refresh-token'));
        $http = new RecordingHttpClient()
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-renewed-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();

        $response = self::client($http, $user, $tokens)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertSame([], $user->redirects);
        self::assertSame('https://auth.test/token', (string) $http->readRequest(3)->getUri());
        self::assertSame('Bearer the-renewed-token', $http->readRequest(4)->getHeader('Authorization'));
    }

    public function testATokenWellClearOfTheLeewayIsPresentedAsItIs(): void
    {
        $tokens = new InMemoryTokenStore();
        // A lifetime near the leeway would flip if the wall clock ticked between the write and the read.
        $tokens->write(self::RESOURCE, new AccessToken('the-stored-token', 'https://auth.test', time() + 3600, 'the-refresh-token'));
        $http = new RecordingHttpClient()
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['ok' => true])
        ;

        self::client($http, null, $tokens)->request(self::mcpRequest(), new NullCancellation());

        self::assertCount(3, $http->requests);
        self::assertSame('Bearer the-stored-token', $http->readRequest(2)->getHeader('Authorization'));
    }

    public function testATokenLapsingAtTheLeewayIsRenewedInstead(): void
    {
        $tokens = new InMemoryTokenStore();
        $tokens->write(self::RESOURCE, new AccessToken('the-stored-token', 'https://auth.test', time() + 30, 'the-refresh-token'));
        $http = new RecordingHttpClient()
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-renewed-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;

        self::client($http, null, $tokens)->request(self::mcpRequest(), new NullCancellation());

        self::assertCount(5, $http->requests);
        self::assertSame('Bearer the-renewed-token', $http->readRequest(4)->getHeader('Authorization'));
    }

    public function testAChallengeFollowingAScopeUpgradeIsTheServersAnswer(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-first-token', 'token_type' => 'Bearer'])
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"')
            ->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer', 'scope' => 'files:write'])
            ->willChallenge(401, self::CHALLENGE)
        ;
        $user = new ScriptedUserAuthorization();

        $response = self::client($http, $user)->request(self::mcpRequest(), new NullCancellation());

        // One re-authorization is spent on the first `401`, so the `401` that follows the step-up is taken
        // as the server's answer rather than as a third grant.
        self::assertSame(401, $response->getStatus());
        self::assertCount(2, $user->redirects);
        self::assertCount(8, $http->requests);
    }

    private static function client(
        RecordingHttpClient $http,
        ?ScriptedUserAuthorization $user = null,
        ?InMemoryTokenStore $tokens = null,
        ?ArrayLogger $logger = null,
        int $maxScopeUpgrades = 2,
        InsufficientScopePolicy $policy = InsufficientScopePolicy::Reauthorize,
    ): AuthorizedHttpClient {
        return new AuthorizedHttpClient(
            self::RESOURCE,
            new AuthorizationOptions(
                'Example MCP Client',
                'http://localhost:3000/callback',
                maxScopeUpgrades: $maxScopeUpgrades,
                onInsufficientScope: $policy,
            ),
            $user ?? new ScriptedUserAuthorization(),
            $http,
            $tokens,
            null,
            $logger ?? new ArrayLogger(),
        );
    }

    private static function mcpRequest(): Request
    {
        return new Request(self::RESOURCE, 'POST', '{"jsonrpc":"2.0","id":1,"method":"ping"}');
    }

    /**
     * Queues the challenge and the four authorization exchanges that answer it. The caller queues what the
     * retried MCP request is answered with.
     */
    private static function scriptChallengeAndFlow(string $challengeBody = '{}'): RecordingHttpClient
    {
        return new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE, $challengeBody)
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
        ;
    }

    /**
     * @return array<string, mixed>
     */
    private static function resourceDocument(): array
    {
        return ['resource' => self::RESOURCE, 'authorization_servers' => ['https://auth.test']];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serverDocument(): array
    {
        return [
            'issuer' => 'https://auth.test',
            'authorization_endpoint' => 'https://auth.test/authorize',
            'token_endpoint' => 'https://auth.test/token',
            'registration_endpoint' => 'https://auth.test/register',
            'code_challenge_methods_supported' => ['S256'],
        ];
    }
}
