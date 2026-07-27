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
use Amp\NullCancellation;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizedHttpClient;
use Nexus\Mcp\Client\Auth\InMemoryTokenStore;
use Nexus\Mcp\Tests\Fixtures\Client\Auth\ScriptedUserAuthorization;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

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
        // The client identifier is already stored by the first round, so the step-up re-registers nothing.
        $http = self::scriptChallengeAndFlow()
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"')
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['access_token' => 'the-wider-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();

        $response = self::client($http, $user)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertSame(['files:write'], $user->readRequestedScopes(1));
        self::assertSame('Bearer the-wider-token', $http->readRequest(9)->getHeader('Authorization'));
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
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
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
            self::assertNull($store->read(self::RESOURCE, 'https://auth.test'));
        }
    }

    public function testTheChallengeBodyIsDrainedSoItsConnectionIsReleased(): void
    {
        $http = self::scriptChallengeAndFlow()->willAnswerJson(['ok' => true]);

        self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertTrue($http->drainedBodies[0] ?? false);
    }

    public function testAnOversizedChallengeBodyStillAuthorizes(): void
    {
        $http = new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE, str_repeat('x', 9000))
            ->willAnswerJson(self::resourceDocument())
            ->willAnswerJson(self::serverDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;

        $response = self::client($http)->request(self::mcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
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

        self::assertSame('the-access-token', $store->read(self::RESOURCE, 'https://auth.test')?->value);
    }

    private static function client(
        RecordingHttpClient $http,
        ?ScriptedUserAuthorization $user = null,
        ?InMemoryTokenStore $tokens = null,
        ?ArrayLogger $logger = null,
        int $maxScopeUpgrades = 2,
    ): AuthorizedHttpClient {
        return new AuthorizedHttpClient(
            self::RESOURCE,
            new AuthorizationOptions('Example MCP Client', 'http://localhost:3000/callback', maxScopeUpgrades: $maxScopeUpgrades),
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
    private static function scriptChallengeAndFlow(): RecordingHttpClient
    {
        return new RecordingHttpClient()
            ->willChallenge(401, self::CHALLENGE)
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
