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

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Interceptor\TooManyRedirectsException;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\NullCancellation;
use Amp\Sync\Semaphore;
use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizedHttpClient;
use Nexus\Mcp\Client\Auth\InMemoryClientRegistrationStore;
use Nexus\Mcp\Client\Auth\InMemoryTokenStore;
use Nexus\Mcp\Client\Auth\InsufficientScopePolicy;
use Nexus\Mcp\Client\Exception\InsufficientScopeException;
use Nexus\Mcp\Client\Exception\RedirectRefusedException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Auth\RetainingSemaphore;
use Nexus\Mcp\Tests\Fixtures\Client\Auth\ScriptedGrantStrategy;
use Nexus\Mcp\Tests\Fixtures\Client\Auth\ScriptedUserAuthorization;
use Nexus\Mcp\Tests\Fixtures\Client\Http\DelegatingInterceptor;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

use function Amp\async;

/**
 * @internal
 */
#[CoversClass(AuthorizedHttpClient::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizedHttpClientTest extends AbstractMcpTestCase
{
    private const string RESOURCE = 'https://127.0.0.1:1/mcp';
    private const string CHALLENGE = 'Bearer resource_metadata="https://127.0.0.1:1/.well-known/oauth-protected-resource/mcp"';

    public function testAnUnprotectedServerIsCalledWithNoAuthorization(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['ok' => true]);

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertCount(1, $http->requests);
        self::assertNull($http->readRequest()->getHeader('Authorization'));
    }

    public function testAChallengeTriggersTheFlowAndTheRequestIsRetriedWithTheToken(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertCount(6, $http->requests);
        self::assertNull($http->readRequest(0)->getHeader('Authorization'));
        self::assertSame('Bearer the-access-token', $http->readRequest(5)->getHeader('Authorization'));
    }

    public function testEveryLegOfTheFlowIsBoundedByTheRequestsOwnCancellation(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();
        $cancellation = new NullCancellation();

        $this->buildClient($http, $user)->request($this->buildMcpRequest(), $cancellation);

        self::assertSame(array_fill(0, 6, $cancellation), $http->cancellations);
        self::assertSame([$cancellation], $user->cancellations);
    }

    public function testATokenIsNeverSentToAnotherOrigin(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
            ->willAnswerJson(['ok' => true])
        ;
        $client = $this->buildClient($http);
        $client->request($this->buildMcpRequest(), new NullCancellation());

        $client->request(new Request('https://attacker.example.com/mcp', 'POST', '{}'), new NullCancellation());

        self::assertSame('Bearer the-access-token', $http->readRequest(5)->getHeader('Authorization'));
        self::assertNull($http->readRequest(6)->getHeader('Authorization'));
    }

    public function testATokenIsNeverSentToAnotherPathOnTheSameOrigin(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
            ->willAnswerJson(['ok' => true])
        ;
        $client = $this->buildClient($http);
        $client->request($this->buildMcpRequest(), new NullCancellation());

        $client->request(new Request('https://127.0.0.1:1/tenant-b/mcp', 'POST', '{}'), new NullCancellation());

        self::assertSame('Bearer the-access-token', $http->readRequest(5)->getHeader('Authorization'));
        self::assertNull($http->readRequest(6)->getHeader('Authorization'));
    }

    public function testASameOriginRedirectLeavingTheResourceIsRefused(): void
    {
        $http = $this->scriptChallengeAndFlow()->willRedirectTo('https://127.0.0.1:1/admin/secrets');

        try {
            $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());
            self::fail('The redirect should have been refused.');
        } catch (RedirectRefusedException $e) {
            self::assertSame(
                'The request to "https://127.0.0.1:1/mcp" was answered from "https://127.0.0.1:1/admin/secrets" after a redirect. Credentials are never carried across one.',
                $e->getMessage(),
            );
        }
    }

    public function testAMetadataDiscoveryRedirectIsNeverFollowed(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willRedirectTo('http://169.254.169.254/latest/meta-data')
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertNotContains(
            'http://169.254.169.254/latest/meta-data',
            array_map(static fn(Request $request): string => (string) $request->getUri(), $http->requests),
            'A redirected well-known probe must be skipped, never fetched.',
        );
    }

    public function testARedirectOffTheMcpServerIsRefusedBeforeTheCredentialTravels(): void
    {
        $http = $this->scriptChallengeAndFlow()->willRedirectTo('https://attacker.example.com/mcp');

        try {
            $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());
            self::fail('The redirect should have been refused.');
        } catch (RedirectRefusedException $e) {
            self::assertSame(
                'The request to "https://127.0.0.1:1/mcp" was answered from "https://attacker.example.com/mcp" after a redirect. Credentials are never carried across one.',
                $e->getMessage(),
            );
        }

        self::assertCount(6, $http->requests);
    }

    public function testASchemeDowngradeIsRefusedBeforeTheCredentialTravels(): void
    {
        $http = $this->scriptChallengeAndFlow()->willRedirectTo('http://127.0.0.1:1/mcp');

        try {
            $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());
            self::fail('The downgrade should have been refused.');
        } catch (RedirectRefusedException $e) {
            self::assertSame(
                'The request to "https://127.0.0.1:1/mcp" was answered from "http://127.0.0.1:1/mcp" after a redirect. Credentials are never carried across one.',
                $e->getMessage(),
            );
        }

        self::assertCount(6, $http->requests);
        self::assertSame('https', $http->readRequest(5)->getUri()->getScheme(), 'Nothing was sent over cleartext.');
    }

    public function testARedirectWithinTheMcpServerIsFollowedWithTheCredential(): void
    {
        $http = $this->scriptChallengeAndFlow()
            ->willRedirectTo('https://127.0.0.1:1/mcp/v2')
            ->willAnswerJson(['ok' => true])
        ;

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertSame('https://127.0.0.1:1/mcp/v2', (string) $http->readRequest(6)->getUri());
        self::assertSame('Bearer the-access-token', $http->readRequest(6)->getHeader('Authorization'));
    }

    #[DataProvider('provideOnlyARedirectStatusIsFollowedCases')]
    public function testOnlyARedirectStatusIsFollowed(int $status, bool $followed): void
    {
        $http = $this->scriptChallengeAndFlow()
            ->willAnswerWithHeaders($status, ['location' => 'https://127.0.0.1:1/mcp/v2'])
            ->willAnswerJson(['ok' => true])
        ;

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertCount($followed ? 7 : 6, $http->requests);
        self::assertSame($followed ? 200 : $status, $response->getStatus());
    }

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function provideOnlyARedirectStatusIsFollowedCases(): iterable
    {
        yield '200 carrying a location' => [200, false];

        yield '300 multiple choices' => [300, false];

        yield '301 moved permanently' => [301, true];

        yield '302 found' => [302, true];

        yield '303 see other' => [303, true];

        yield '304 not modified' => [304, false];

        yield '305 use proxy' => [305, false];

        yield '307 temporary redirect' => [307, false];

        yield '308 permanent redirect' => [308, false];

        yield '309 unassigned' => [309, false];
    }

    public function testAnUntokenedRequestToThisServerIsStillRedirectControlled(): void
    {
        $http = (new RecordingHttpClient())->willRedirectTo('http://127.0.0.1:1/mcp');
        $request = $this->buildMcpRequest();
        $request->setHeader('Authorization', 'Bearer a-token-of-the-callers-own');

        $this->expectException(RedirectRefusedException::class);

        $this->buildClient($http)->request($request, new NullCancellation());
    }

    public function testAChallengeCannotBeAnsweredFromOffOrigin(): void
    {
        $http = (new RecordingHttpClient())->willRedirectTo('https://attacker.example.com/mcp');

        try {
            $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());
            self::fail('The redirect should have been refused.');
        } catch (RedirectRefusedException) {
            self::assertCount(1, $http->requests, 'Nothing was sent to the redirect target.');
        }
    }

    #[DataProvider('provideAGetIsReplayedByAMethodPreservingRedirectCases')]
    public function testAGetIsReplayedByAMethodPreservingRedirect(int $status): void
    {
        $http = $this->scriptChallengeAndFlow()
            ->willAnswerWithHeaders($status, ['location' => 'https://127.0.0.1:1/mcp/v2'])
            ->willAnswerJson(['ok' => true])
        ;

        $response = $this->buildClient($http)->request(
            new Request(self::RESOURCE, 'GET'),
            new NullCancellation(),
        );

        self::assertSame(200, $response->getStatus());
        self::assertCount(7, $http->requests);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideAGetIsReplayedByAMethodPreservingRedirectCases(): iterable
    {
        yield '307 temporary redirect' => [307];

        yield '308 permanent redirect' => [308];
    }

    public function testTheHopDropsTheBodyFramingOfTheRequestItReplaces(): void
    {
        $http = $this->scriptChallengeAndFlow()
            ->willRedirectTo('https://127.0.0.1:1/mcp/v2')
            ->willAnswerJson(['ok' => true])
        ;
        $request = $this->buildMcpRequest();
        $request->setHeader('content-type', 'application/json');
        $request->setHeader('content-length', '40');
        $request->setHeader('transfer-encoding', 'chunked');

        $this->buildClient($http)->request($request, new NullCancellation());

        $hop = $http->readRequest(6);
        self::assertNull($hop->getHeader('content-type'));
        self::assertNull($hop->getHeader('content-length'));
        self::assertNull($hop->getHeader('transfer-encoding'));
    }

    public function testTheRedirectBudgetIsSpentExactlyOnce(): void
    {
        $withinBudget = $this->scriptChallengeAndFlow();

        for ($hop = 1; $hop <= 10; ++$hop) {
            $withinBudget->willRedirectTo('https://127.0.0.1:1/mcp/'.$hop);
        }

        $withinBudget->willAnswerJson(['ok' => true]);

        self::assertSame(
            200,
            $this->buildClient($withinBudget)->request($this->buildMcpRequest(), new NullCancellation())->getStatus(),
            'Ten hops is the budget an unsealed client would have spent.',
        );
    }

    public function testAnAnswerNamingTwoLocationsIsNotFollowed(): void
    {
        $http = $this->scriptChallengeAndFlow()->willAnswerWithHeaders(
            302,
            ['location' => ['https://127.0.0.1:1/a', 'https://127.0.0.1:1/b']],
        );

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(302, $response->getStatus());
        self::assertCount(6, $http->requests);
    }

    public function testAnUnreadableLocationIsNotFollowed(): void
    {
        $http = $this->scriptChallengeAndFlow()->willAnswerWithHeaders(302, ['location' => ':://not a uri']);

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(302, $response->getStatus());
        self::assertCount(6, $http->requests);
    }

    public function testAnEndlessRedirectLoopIsReportedAsTooManyRedirects(): void
    {
        $http = $this->scriptChallengeAndFlow();

        for ($hop = 1; $hop <= 11; ++$hop) {
            $http->willRedirectTo('https://127.0.0.1:1/mcp/'.$hop);
        }

        $http->willAnswerJson(['ok' => true]);

        $this->expectException(TooManyRedirectsException::class);

        $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());
    }

    public function testAFollowedRedirectIsChainedOntoTheAnswerItProduced(): void
    {
        $http = $this->scriptChallengeAndFlow()
            ->willRedirectTo('https://127.0.0.1:1/mcp/v2')
            ->willAnswerJson(['ok' => true])
        ;

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(302, $response->getPreviousResponse()?->getStatus());
    }

    public function testAFollowedRedirectDrainsTheAnswerItReplaces(): void
    {
        $http = $this->scriptChallengeAndFlow()
            ->willRedirectTo('https://127.0.0.1:1/mcp/v2')
            ->willAnswerJson(['ok' => true])
        ;

        $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertTrue($http->drainedBodies[5] ?? false, 'The redirect answer is drained before the hop.');
    }

    public function testFollowingARedirectLeavesTheRequestThatProducedItUntouched(): void
    {
        $http = $this->scriptChallengeAndFlow()
            ->willRedirectTo('https://127.0.0.1:1/mcp/v2')
            ->willAnswerJson(['ok' => true])
        ;

        $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame('https://127.0.0.1:1/mcp', (string) $http->readRequest(5)->getUri());
        self::assertSame('POST', $http->readRequest(5)->getMethod());
        self::assertSame('GET', $http->readRequest(6)->getMethod(), 'The hop is a GET, as the client would send.');
    }

    public function testARefusedRedirectIsDrainedSoItsConnectionIsReleased(): void
    {
        $http = $this->scriptChallengeAndFlow()->willRedirectTo('http://127.0.0.1:1/mcp');

        try {
            $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());
            self::fail('The redirect should have been refused.');
        } catch (RedirectRefusedException) {
            self::assertTrue($http->drainedBodies[5] ?? false);
        }
    }

    public function testAChallengeFromAnotherOriginIsReturnedRatherThanActedOn(): void
    {
        $http = (new RecordingHttpClient())->willChallenge(
            401,
            'Bearer resource_metadata="https://attacker.example.com/prm", scope="admin:everything"',
        );

        $response = $this->buildClient($http)->request(
            new Request('https://attacker.example.com/mcp', 'POST', '{}'),
            new NullCancellation(),
        );

        self::assertSame(401, $response->getStatus());
        self::assertCount(1, $http->requests);
    }

    public function testAnUnauthenticatedRequestThatFollowedARedirectIsLeftAlone(): void
    {
        $http = (new RecordingHttpClient())->willAnswerFrom('https://elsewhere.example.com/mcp', ['ok' => true]);

        self::assertSame(200, $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation())->getStatus());
    }

    public function testTheChallengeSteersDiscoveryToTheAdvertisedUrl(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, 'Bearer resource_metadata="https://127.0.0.1:1/custom/prm"')
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;

        $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame('https://127.0.0.1:1/custom/prm', (string) $http->readRequest(1)->getUri());
    }

    public function testTheChallengeScopeIsRequested(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, 'Bearer resource_metadata="https://127.0.0.1:1/.well-known/oauth-protected-resource/mcp", scope="files:read"')
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();

        $this->buildClient($http, $user)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(['files:read'], $user->readRequestedScopes());
    }

    public function testAStoredTokenIsPresentedOnALaterRequest(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
            ->willAnswerJson(['ok' => true])
        ;
        $client = $this->buildClient($http);

        $client->request($this->buildMcpRequest(), new NullCancellation());
        $client->request($this->buildMcpRequest(), new NullCancellation());

        self::assertCount(7, $http->requests);
        self::assertSame('Bearer the-access-token', $http->readRequest(6)->getHeader('Authorization'));
    }

    public function testASecondUnauthorizedAnswerIsReturnedRatherThanRetriedForever(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willChallenge(401, self::CHALLENGE)
        ;

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(401, $response->getStatus());
        self::assertCount(6, $http->requests);
    }

    public function testAnInsufficientScopeChallengeStepsTheScopesUpAndRetries(): void
    {
        $http = $this->scriptChallengeAndFlow()
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"')
            ->willAnswerJson(['access_token' => 'the-wider-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();

        $response = $this->buildClient($http, $user)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertSame(['files:write'], $user->readRequestedScopes(1));
        self::assertCount(8, $http->requests);
        self::assertSame('Bearer the-wider-token', $http->readRequest(7)->getHeader('Authorization'));
        self::assertTrue($http->drainedBodies[5] ?? false);
    }

    public function testAScopeChallengeNamingNothingTheTokenLacksIsReported(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer', 'scope' => 'files:read'])
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:read"')
        ;
        $user = new ScriptedUserAuthorization();
        $logger = new ArrayLogger();

        try {
            $this->buildClient($http, $user, logger: $logger)->request($this->buildMcpRequest(), new NullCancellation());

            self::fail('An unwinnable scope challenge must be reported to the caller.');
        } catch (InsufficientScopeException $e) {
            self::assertSame(['files:read'], $e->required);
        }

        self::assertCount(6, $http->requests);
        self::assertCount(1, $user->redirects);
        self::assertTrue($http->drainedBodies[5] ?? false);
        self::assertSame(
            [['level' => LogLevel::WARNING, 'message' => 'The scope challenge from {resource} names {scopes}.', 'context' => [
                'resource' => self::RESOURCE,
                'scopes' => 'files:read',
            ]]],
            $logger->recordsMatching(LogLevel::WARNING, 'The scope challenge from {resource} names {scopes}.'),
        );
    }

    public function testAScopeChallengeNamingOnlyUnusableScopesSaysSoRatherThanClaimingItNamedNone(): void
    {
        $http = $this->scriptChallengeAndFlow()->willChallenge(403, "Bearer error=\"insufficient_scope\", scope=\"fil\xc3\xa9s:read\"");
        $user = new ScriptedUserAuthorization();
        $logger = new ArrayLogger();

        try {
            $this->buildClient($http, $user, logger: $logger)->request($this->buildMcpRequest(), new NullCancellation());

            self::fail('A challenge naming only unusable scopes must be reported to the caller.');
        } catch (InsufficientScopeException $e) {
            self::assertSame([], $e->required);
            self::assertSame(
                'The MCP server named only scopes that are not RFC 6749 scope-tokens, so none can be requested.',
                $e->getMessage(),
            );
        }

        self::assertSame(
            [['level' => LogLevel::WARNING, 'message' => 'The scope challenge from {resource} names {scopes}.', 'context' => [
                'resource' => self::RESOURCE,
                'scopes' => 'only scopes that are not RFC 6749 scope-tokens',
            ]]],
            $logger->recordsMatching(LogLevel::WARNING, 'The scope challenge from {resource} names {scopes}.'),
            'The log record must not claim the challenge named nothing when it named a scope this client dropped.',
        );
    }

    public function testAScopeChallengeNamingNoScopeAtAllIsReported(): void
    {
        $http = $this->scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="insufficient_scope"');
        $user = new ScriptedUserAuthorization();
        $logger = new ArrayLogger();

        try {
            $this->buildClient($http, $user, logger: $logger)->request($this->buildMcpRequest(), new NullCancellation());

            self::fail('An unwinnable scope challenge must be reported to the caller.');
        } catch (InsufficientScopeException $e) {
            self::assertSame([], $e->required);
            self::assertSame('The MCP server answered insufficient_scope without naming a scope.', $e->getMessage());
        }

        self::assertCount(6, $http->requests);
        self::assertCount(1, $user->redirects);
        self::assertSame(
            [['level' => LogLevel::WARNING, 'message' => 'The scope challenge from {resource} names {scopes}.', 'context' => [
                'resource' => self::RESOURCE,
                'scopes' => 'no scope at all',
            ]]],
            $logger->recordsMatching(LogLevel::WARNING, 'The scope challenge from {resource} names {scopes}.'),
        );
    }

    public function testASecondScopeChallengeAsksForWhatTheFirstOneDidAsWell(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'token-1', 'token_type' => 'Bearer', 'scope' => 'files:read'])
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"')
            ->willAnswerJson(['access_token' => 'token-2', 'token_type' => 'Bearer', 'scope' => 'files:read'])
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:admin"')
            ->willAnswerJson(['access_token' => 'token-3', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();

        $response = $this->buildClient($http, $user)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertCount(10, $http->requests);
        self::assertSame(['files:write', 'files:read'], $user->readRequestedScopes(1));
        self::assertSame(['files:admin', 'files:write', 'files:read'], $user->readRequestedScopes(2));
    }

    public function testAScopeTheTokenLostToANarrowerGrantIsAskedForAgain(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-wide-token', 'token_type' => 'Bearer', 'scope' => 'files:admin'])
            ->willAnswerJson(['ok' => true])
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['access_token' => 'the-narrow-token', 'token_type' => 'Bearer', 'scope' => 'files:read'])
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:admin"')
            ->willAnswerJson(['access_token' => 'the-widened-token', 'token_type' => 'Bearer', 'scope' => 'files:read files:admin'])
            ->willAnswerJson(['ok' => true])
        ;
        $client = $this->buildClient($http);
        $client->request($this->buildMcpRequest(), new NullCancellation());

        $response = $client->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertCount(13, $http->requests);
        self::assertSame('Bearer the-widened-token', $http->readRequest(12)->getHeader('Authorization'));
    }

    public function testTheFailPolicyReportsTheChallengedScopesInsteadOfAskingForThem(): void
    {
        $http = $this->scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write files:admin"');
        $user = new ScriptedUserAuthorization();

        $this->expectException(InsufficientScopeException::class);
        $this->expectExceptionMessageIs('The MCP server requires the scope "files:write files:admin".');

        $this->buildClient($http, $user, policy: InsufficientScopePolicy::Fail)->request($this->buildMcpRequest(), new NullCancellation());
    }

    public function testTheFailPolicyDistinguishesAChallengeThatNamedNoScope(): void
    {
        $http = $this->scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="insufficient_scope"');
        $user = new ScriptedUserAuthorization();

        $this->expectException(InsufficientScopeException::class);
        $this->expectExceptionMessageIs('The MCP server answered insufficient_scope without naming a scope.');

        $this->buildClient($http, $user, policy: InsufficientScopePolicy::Fail)->request($this->buildMcpRequest(), new NullCancellation());
    }

    public function testTheFailPolicyDistinguishesAChallengeWhoseScopesWereAllDropped(): void
    {
        $http = $this->scriptChallengeAndFlow()->willChallenge(403, "Bearer error=\"insufficient_scope\", scope=\"fil\xc3\xa9s:read\"");
        $user = new ScriptedUserAuthorization();

        $this->expectException(InsufficientScopeException::class);
        $this->expectExceptionMessageIs('The MCP server named only scopes that are not RFC 6749 scope-tokens, so none can be requested.');

        $this->buildClient($http, $user, policy: InsufficientScopePolicy::Fail)->request($this->buildMcpRequest(), new NullCancellation());
    }

    public function testTheFailPolicyOpensNoSecondConsentScreen(): void
    {
        $http = $this->scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"');
        $user = new ScriptedUserAuthorization();

        try {
            $this->buildClient($http, $user, policy: InsufficientScopePolicy::Fail)->request($this->buildMcpRequest(), new NullCancellation());
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
        $http = $this->scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"');
        $logger = new ArrayLogger();

        try {
            $this->buildClient($http, logger: $logger, maxScopeUpgrades: 0, policy: InsufficientScopePolicy::Fail)
                ->request($this->buildMcpRequest(), new NullCancellation())
            ;

            self::fail('The insufficient-scope answer should have surfaced.');
        } catch (InsufficientScopeException $e) {
            self::assertSame(['files:write'], $e->required);
        }

        self::assertSame([], $logger->recordsMatching(LogLevel::WARNING, 'Giving up on {resource} after {attempts} scope upgrades.'));
    }

    public function testTheFailPolicyStillReportsAChallengeNamingNothingNew(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer', 'scope' => 'files:read'])
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:read"')
        ;
        $logger = new ArrayLogger();

        try {
            $this->buildClient($http, logger: $logger, policy: InsufficientScopePolicy::Fail)->request($this->buildMcpRequest(), new NullCancellation());

            self::fail('The insufficient-scope answer should have surfaced.');
        } catch (InsufficientScopeException $e) {
            self::assertSame(['files:read'], $e->required);
        }

        self::assertSame([], $logger->recordsMatching(LogLevel::WARNING, 'The scope challenge from {resource} names {scopes}.'));
    }

    public function testALapsedTokenLeavesItsScopesToTheGrantThatReplacesIt(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'token-1', 'token_type' => 'Bearer', 'scope' => 'files:read', 'expires_in' => 1])
            ->willAnswerJson(['ok' => true])
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['access_token' => 'token-2', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();
        $client = $this->buildClient($http, $user);

        $client->request($this->buildMcpRequest(), new NullCancellation());
        $client->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(['files:read'], $user->readRequestedScopes(1));
    }

    public function testConcurrentChallengesOpenOneConsentScreen(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();
        $client = $this->buildClient($http, $user);

        $first = async(fn(): Response => $client->request($this->buildMcpRequest(), new NullCancellation()));
        $second = async(fn(): Response => $client->request($this->buildMcpRequest(), new NullCancellation()));

        $first->await();
        $second->await();

        self::assertCount(1, $user->redirects);
    }

    public function testAForbiddenAnswerThatIsNotAScopeChallengeIsReturned(): void
    {
        $http = $this->scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="invalid_token"');

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(403, $response->getStatus());
        self::assertCount(6, $http->requests);
    }

    public function testAForbiddenAnswerWithNoChallengeIsReturned(): void
    {
        $http = $this->scriptChallengeAndFlow()->willAnswerJson(['error' => 'nope'], 403);

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(403, $response->getStatus());
        self::assertCount(6, $http->requests);
    }

    public function testScopeUpgradesAreCappedAndTheExhaustionIsReported(): void
    {
        $http = $this->scriptChallengeAndFlow()
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"')
            ->willAnswerJson(['access_token' => 'the-wider-token', 'token_type' => 'Bearer'])
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:admin"')
        ;
        $logger = new ArrayLogger();

        try {
            $this->buildClient($http, logger: $logger, maxScopeUpgrades: 1)->request($this->buildMcpRequest(), new NullCancellation());

            self::fail('A spent upgrade budget must be reported to the caller.');
        } catch (InsufficientScopeException $e) {
            self::assertSame(['files:write', 'files:admin'], $e->required);
        }

        self::assertTrue($http->drainedBodies[7] ?? false);
        self::assertSame(
            [['level' => LogLevel::WARNING, 'message' => 'Giving up on {resource} after {attempts} scope upgrades.', 'context' => [
                'resource' => self::RESOURCE,
                'attempts' => 1,
            ]]],
            $logger->recordsMatching(LogLevel::WARNING, 'Giving up on {resource} after {attempts} scope upgrades.'),
        );
    }

    public function testASpentUpgradeBudgetDistinguishesAChallengeThatNamedNoScope(): void
    {
        $http = $this->scriptChallengeAndFlow()->willChallenge(403, 'Bearer error="insufficient_scope"');

        try {
            $this->buildClient($http, maxScopeUpgrades: 0)->request($this->buildMcpRequest(), new NullCancellation());

            self::fail('A spent upgrade budget must be reported to the caller.');
        } catch (InsufficientScopeException $e) {
            self::assertSame([], $e->required);
            self::assertSame('The MCP server answered insufficient_scope without naming a scope.', $e->getMessage());
        }
    }

    public function testASpentUpgradeBudgetDistinguishesAChallengeWhoseScopesWereAllDropped(): void
    {
        $http = $this->scriptChallengeAndFlow()->willChallenge(403, "Bearer error=\"insufficient_scope\", scope=\"fil\xc3\xa9s:read\"");

        try {
            $this->buildClient($http, maxScopeUpgrades: 0)->request($this->buildMcpRequest(), new NullCancellation());

            self::fail('A spent upgrade budget must be reported to the caller.');
        } catch (InsufficientScopeException $e) {
            self::assertSame([], $e->required);
            self::assertSame(
                'The MCP server named only scopes that are not RFC 6749 scope-tokens, so none can be requested.',
                $e->getMessage(),
            );
        }
    }

    public function testAnUnauthorizedAnswerWithNoChallengeStillStartsDiscovery(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson([], 401)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertSame(
            'https://127.0.0.1:1/.well-known/oauth-protected-resource/mcp',
            (string) $http->readRequest(1)->getUri(),
        );
    }

    public function testARejectedTokenCarriesItsGrantedScopesIntoTheNextOne(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer', 'scope' => 'files:read'])
            ->willAnswerJson(['ok' => true])
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();
        $client = $this->buildClient($http, $user);

        $client->request($this->buildMcpRequest(), new NullCancellation());
        $client->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(['files:read'], $user->readRequestedScopes(1));
    }

    public function testARejectedTokenIsDroppedEvenWhenReauthorizingFails(): void
    {
        $http = $this->scriptChallengeAndFlow()
            ->willAnswerJson(['ok' => true])
            ->willChallenge(401, self::CHALLENGE)
            ->willFail(new HttpException('The network is gone.'))
        ;
        $store = new InMemoryTokenStore();
        $client = $this->buildClient($http, tokens: $store);
        $client->request($this->buildMcpRequest(), new NullCancellation());

        try {
            $client->request($this->buildMcpRequest(), new NullCancellation());
            self::fail('The failed discovery should have surfaced.');
        } catch (HttpException) {
            self::assertNull($store->read(self::RESOURCE));
        }
    }

    public function testAFreshChallengeFollowsTheResourceToAnotherAuthorizationServer(): void
    {
        $http = $this->scriptChallengeAndFlow()
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
        $client = $this->buildClient($http, tokens: $store);

        $client->request($this->buildMcpRequest(), new NullCancellation());
        $client->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame('https://successor.test', $store->read(self::RESOURCE)?->issuer);
        self::assertSame('https://successor.test/token', (string) $http->readRequest(10)->getUri());
    }

    public function testTheChallengeBodyIsDrainedSoItsConnectionIsReleased(): void
    {
        $http = $this->scriptChallengeAndFlow()->willAnswerJson(['ok' => true]);

        $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertTrue($http->drainedBodies[0] ?? false);
    }

    public function testAChallengeBodyAtTheDrainCapIsStillRead(): void
    {
        $http = $this->scriptChallengeAndFlow(str_repeat('x', 8_192))->willAnswerJson(['ok' => true]);

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertTrue($http->drainedBodies[0] ?? false);
    }

    public function testAnOversizedChallengeBodyIsGivenUpOnButStillAuthorizes(): void
    {
        $http = $this->scriptChallengeAndFlow(str_repeat('x', 8_193))->willAnswerJson(['ok' => true]);

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertFalse($http->drainedBodies[0] ?? false);
    }

    public function testAChallengeBodyThatFailsPartwayThroughIsGivenUpOnButStillAuthorizes(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallengeWithAnUnreadableBody(401, self::CHALLENGE, new HttpException('Invalid hexadecimal chunk size.'))
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;

        $response = $this->buildClient($http)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertSame('Bearer the-access-token', $http->readRequest(5)->getHeader('Authorization'));
    }

    public function testTheCallerRequestIsNotMutatedByTheRetry(): void
    {
        $http = $this->scriptChallengeAndFlow()->willAnswerJson(['ok' => true]);
        $request = $this->buildMcpRequest();

        $this->buildClient($http)->request($request, new NullCancellation());

        self::assertNull($request->getHeader('Authorization'));
    }

    public function testASuppliedTokenStoreIsUsed(): void
    {
        $store = new InMemoryTokenStore();

        $this->buildClient($this->scriptChallengeAndFlow()->willAnswerJson(['ok' => true]), tokens: $store)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame('the-access-token', $store->read(self::RESOURCE)?->value);
    }

    public function testASuppliedRegistrationStoreIsUsed(): void
    {
        $store = new InMemoryClientRegistrationStore();

        $this->buildClient($this->scriptChallengeAndFlow()->willAnswerJson(['ok' => true]), registrations: $store)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame('the-client', $store->read('https://auth.test')?->clientId);
    }

    public function testAStoredTokenPastItsLifetimeIsRenewedBeforeTheRequestIsSent(): void
    {
        $tokens = new InMemoryTokenStore();
        $tokens->write(self::RESOURCE, new AccessToken('the-stored-token', 'https://auth.test', time() - 1, 'the-refresh-token'));
        $http = (new RecordingHttpClient())
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-renewed-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $user = new ScriptedUserAuthorization();

        $response = $this->buildClient($http, $user, $tokens)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertSame([], $user->redirects);
        self::assertSame('https://auth.test/token', (string) $http->readRequest(3)->getUri());
        self::assertSame('Bearer the-renewed-token', $http->readRequest(4)->getHeader('Authorization'));
    }

    public function testARenewalHoldsTheInjectedLock(): void
    {
        $tokens = new InMemoryTokenStore();
        $tokens->write(self::RESOURCE, new AccessToken('the-stored-token', 'https://auth.test', time() - 1, 'the-refresh-token'));
        $http = (new RecordingHttpClient())
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-renewed-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;
        $semaphore = new RetainingSemaphore();

        $response = $this->buildClient($http, null, $tokens, lock: $semaphore)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertCount(1, $semaphore->minted, 'The renewal must take the caller-supplied permit, not a private one.');
        self::assertSame(1, $semaphore->released);
        self::assertFalse($semaphore->isHeld());
    }

    public function testATokenWellClearOfTheLeewayIsPresentedAsItIs(): void
    {
        $tokens = new InMemoryTokenStore();
        $tokens->write(self::RESOURCE, new AccessToken('the-stored-token', 'https://auth.test', time() + 3_600, 'the-refresh-token'));
        $http = (new RecordingHttpClient())
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['ok' => true])
        ;

        $this->buildClient($http, null, $tokens)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertCount(3, $http->requests);
        self::assertSame('Bearer the-stored-token', $http->readRequest(2)->getHeader('Authorization'));
    }

    public function testATokenLapsingAtTheLeewayIsRenewedInstead(): void
    {
        $tokens = new InMemoryTokenStore();
        $tokens->write(self::RESOURCE, new AccessToken('the-stored-token', 'https://auth.test', time() + 30, 'the-refresh-token'));
        $http = (new RecordingHttpClient())
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-renewed-token', 'token_type' => 'Bearer'])
            ->willAnswerJson(['ok' => true])
        ;

        $this->buildClient($http, null, $tokens)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertCount(5, $http->requests);
        self::assertSame('Bearer the-renewed-token', $http->readRequest(4)->getHeader('Authorization'));
    }

    public function testAChallengeFollowingAScopeUpgradeIsTheServersAnswer(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-first-token', 'token_type' => 'Bearer'])
            ->willChallenge(403, 'Bearer error="insufficient_scope", scope="files:write"')
            ->willAnswerJson(['access_token' => 'the-second-token', 'token_type' => 'Bearer', 'scope' => 'files:write'])
            ->willChallenge(401, self::CHALLENGE)
        ;
        $user = new ScriptedUserAuthorization();

        $response = $this->buildClient($http, $user)->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(401, $response->getStatus());
        self::assertCount(2, $user->redirects);
        self::assertCount(8, $http->requests);
    }

    public function testAGrantStrategyRunsTheFlowWithoutAnyUserAuthorization(): void
    {
        $http = (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['ok' => true])
        ;
        $strategy = new ScriptedGrantStrategy(true, new AccessToken('the-machine-token', 'https://auth.test'));

        $client = new AuthorizedHttpClient(
            self::RESOURCE,
            new AuthorizationOptions('Example MCP Client'),
            null,
            $this->makeBuilderFor($http),
            grantStrategy: $strategy,
        );
        $response = $client->request($this->buildMcpRequest(), new NullCancellation());

        self::assertSame(200, $response->getStatus());
        self::assertCount(1, $strategy->contexts);
        self::assertSame('Bearer the-machine-token', $http->readRequest(3)->getHeader('Authorization'));
    }

    public function testAClientWithNeitherAUserAuthorizationNorAGrantStrategyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The client needs a user authorization or a grant strategy to obtain tokens, and neither was given.');

        new AuthorizedHttpClient(
            self::RESOURCE,
            new AuthorizationOptions('Example MCP Client'),
            null,
            $this->makeBuilderFor(new RecordingHttpClient()),
        );
    }

    public function testAUserAuthorizationWithoutARedirectUriIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('A user authorization needs a redirect URI, and the authorization options carry none.');

        new AuthorizedHttpClient(
            self::RESOURCE,
            new AuthorizationOptions('Example MCP Client'),
            new ScriptedUserAuthorization(),
            $this->makeBuilderFor(new RecordingHttpClient()),
        );
    }

    public function testAClientWithBothAUserAuthorizationAndAGrantStrategyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('A user authorization and a grant strategy were both given, and the client can run only one.');

        new AuthorizedHttpClient(
            self::RESOURCE,
            new AuthorizationOptions('Example MCP Client'),
            new ScriptedUserAuthorization(),
            $this->makeBuilderFor(new RecordingHttpClient()),
            grantStrategy: new ScriptedGrantStrategy(true),
        );
    }

    private function makeBuilderFor(RecordingHttpClient $http): HttpClientBuilder
    {
        return (new HttpClientBuilder())->intercept(new DelegatingInterceptor($http));
    }

    private function buildClient(
        RecordingHttpClient $http,
        ?ScriptedUserAuthorization $user = null,
        ?InMemoryTokenStore $tokens = null,
        ?InMemoryClientRegistrationStore $registrations = null,
        ?ArrayLogger $logger = null,
        int $maxScopeUpgrades = 2,
        InsufficientScopePolicy $policy = InsufficientScopePolicy::Reauthorize,
        ?Semaphore $lock = null,
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
            $this->makeBuilderFor($http),
            $tokens,
            $registrations,
            $logger ?? new ArrayLogger(),
            lock: $lock,
        );
    }

    private function buildMcpRequest(): Request
    {
        return new Request(self::RESOURCE, 'POST', '{"jsonrpc":"2.0","id":1,"method":"ping"}');
    }

    private function scriptChallengeAndFlow(string $challengeBody = '{}'): RecordingHttpClient
    {
        return (new RecordingHttpClient())
            ->willChallenge(401, self::CHALLENGE, $challengeBody)
            ->willAnswerJson($this->buildResourceDocument())
            ->willAnswerJson($this->buildServerDocument())
            ->willAnswerJson(['client_id' => 'the-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
        ;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResourceDocument(): array
    {
        return ['resource' => self::RESOURCE, 'authorization_servers' => ['https://auth.test']];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildServerDocument(): array
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
