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

use Amp\Http\Client\Request;
use Amp\NullCancellation;
use Nexus\Mcp\Client\Auth\AuthorizationCodeGrantStrategy;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\AuthorizationRedirect;
use Nexus\Mcp\Client\Auth\ClientRegistrar;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Auth\DiscoveredResource;
use Nexus\Mcp\Client\Auth\GrantContext;
use Nexus\Mcp\Client\Auth\InMemoryClientRegistrationStore;
use Nexus\Mcp\Client\Auth\PkcePair;
use Nexus\Mcp\Client\Auth\TokenEndpoint;
use Nexus\Mcp\Client\Auth\UserAuthorizationInterface;
use Nexus\Mcp\Client\Exception\InvalidAuthorizationResponseException;
use Nexus\Mcp\Client\Exception\PkceNotSupportedException;
use Nexus\Mcp\Client\Exception\UntrustedAuthorizationMetadataException;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Auth\ProtectedResourceMetadata;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Core\Exception\RuntimeException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Auth\RawCallbackUserAuthorization;
use Nexus\Mcp\Tests\Fixtures\Client\Auth\ScriptedUserAuthorization;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function Amp\ByteStream\buffer;

/**
 * @internal
 */
#[CoversClass(AuthorizationCodeGrantStrategy::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class AuthorizationCodeGrantStrategyTest extends AbstractMcpTestCase
{
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string ISSUER = 'https://auth.example.com';
    private const string AUTHORIZATION_ENDPOINT = 'https://auth.example.com/authorize';

    public function testGrantRegistersAuthorizesAndExchangesTheCode(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson(['client_id' => 'the-registered-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
        ;
        $user = new ScriptedUserAuthorization();

        $token = (new AuthorizationCodeGrantStrategy($user))->grant(
            $this->buildContext($http, new ScopeSet(['files:read'])),
            new NullCancellation(),
        );

        self::assertSame('the-access-token', $token->value);
        self::assertSame(self::ISSUER, $token->issuer);
        self::assertSame(['files:read'], $user->readRequestedScopes());
        self::assertSame('https://auth.example.com/register', (string) $http->readRequest(0)->getUri());
        self::assertSame('https://auth.example.com/token', (string) $http->readRequest(1)->getUri());

        $form = $this->readForm($http->readRequest(1));
        self::assertSame('authorization_code', $form['grant_type'] ?? null);
        self::assertSame('http://localhost:3000/callback', $form['redirect_uri'] ?? null);
        self::assertSame(self::RESOURCE, $form['resource'] ?? null);
        self::assertArrayHasKey('code', $form);
        self::assertArrayHasKey('code_verifier', $form);
    }

    public function testGrantOmitsTheConsentPromptWhenTheOptionsAskForIt(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson(['client_id' => 'the-registered-client'])
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
        ;
        $user = new ScriptedUserAuthorization();
        $options = new AuthorizationOptions(
            'Example MCP Client',
            'http://localhost:3000/callback',
            skipOfflineAccessConsent: true,
        );

        (new AuthorizationCodeGrantStrategy($user))->grant(
            $this->buildContext(
                $http,
                new ScopeSet(['offline_access']),
                $options,
            ),
            new NullCancellation(),
        );

        self::assertStringNotContainsString('prompt=', $user->readRedirect()->url);
    }

    /**
     * @param null|list<non-empty-string> $methods
     */
    #[DataProvider('provideGrantRefusesAServerWithoutS256Cases')]
    public function testGrantRefusesAServerWithoutS256(?array $methods): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(PkceNotSupportedException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not advertise the S256 code challenge method, so authorization cannot proceed.');

        (new AuthorizationCodeGrantStrategy(new ScriptedUserAuthorization()))->grant(
            $this->buildContext($http, new ScopeSet(), methods: $methods),
            new NullCancellation(),
        );
    }

    /**
     * @return iterable<string, array{null|list<non-empty-string>}>
     */
    public static function provideGrantRefusesAServerWithoutS256Cases(): iterable
    {
        yield 'no methods advertised' => [null];

        yield 'only plain advertised' => [['plain']];
    }

    public function testGrantRefusesToRunWithoutARedirectUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The authorization-code grant needs a redirect URI, and the authorization options carry none.');

        (new AuthorizationCodeGrantStrategy(new ScriptedUserAuthorization()))->grant(
            $this->buildContext(
                new RecordingHttpClient(),
                new ScopeSet(),
                new AuthorizationOptions('Example MCP Client'),
            ),
            new NullCancellation(),
        );
    }

    public function testItRenewsThroughTheChallengeRatherThanByAFreshGrant(): void
    {
        self::assertFalse((new AuthorizationCodeGrantStrategy(new ScriptedUserAuthorization()))->renewsByFreshGrant());
    }

    public function testTheRedirectCarriesEveryRequiredParameter(): void
    {
        $redirect = $this->authorize(new ScopeSet(['files:read', 'files:write']));

        self::assertSame([
            'response_type' => 'code',
            'client_id' => 'https://app.example.com/client.json',
            'redirect_uri' => 'http://localhost:3000/callback',
            'state' => $redirect->state,
            'code_challenge' => $redirect->pkce->challenge,
            'code_challenge_method' => 'S256',
            'resource' => 'https://mcp.example.com/mcp',
            'scope' => 'files:read files:write',
        ], $this->readQuery($redirect->url));
    }

    public function testTheRedirectTargetsTheAuthorizationEndpoint(): void
    {
        self::assertStringStartsWith('https://auth.example.com/authorize?', $this->authorize(new ScopeSet())->url);
    }

    public function testTheRedirectOmitsTheScopeParameterForAnEmptyScopeSet(): void
    {
        self::assertArrayNotHasKey('scope', $this->readQuery($this->authorize(new ScopeSet())->url));
    }

    public function testTheRedirectKeepsAQueryTheAuthorizationEndpointAlreadyCarries(): void
    {
        $redirect = $this->authorize(new ScopeSet(), 'https://auth.example.com/authorize?tenant=acme');

        self::assertStringStartsWith('https://auth.example.com/authorize?tenant=acme&', $redirect->url);
        self::assertSame('acme', $this->readQuery($redirect->url)['tenant'] ?? null);
    }

    public function testTheRedirectForcesAConsentScreenWhenItAsksForOfflineAccess(): void
    {
        $redirect = $this->authorize(new ScopeSet(['files:read', 'offline_access']));

        self::assertSame('consent', $this->readQuery($redirect->url)['prompt'] ?? null);
    }

    public function testTheRedirectLeavesTheConsentScreenToTheServerWhenAskedForOfflineAccessWithoutOne(): void
    {
        $redirect = $this->authorize(
            new ScopeSet(['files:read', 'offline_access']),
            options: $this->buildPreRegisteredOptions(skipOfflineAccessConsent: true),
        );

        $query = $this->readQuery($redirect->url);
        self::assertSame('files:read offline_access', $query['scope'] ?? null);
        self::assertArrayNotHasKey('prompt', $query);
    }

    public function testTheRedirectLeavesTheConsentScreenToTheServerOtherwise(): void
    {
        self::assertArrayNotHasKey('prompt', $this->readQuery($this->authorize(new ScopeSet(['files:read']))->url));
    }

    public function testTheRedirectRecordsTheIssuerToValidateTheResponseAgainst(): void
    {
        self::assertSame(self::ISSUER, $this->authorize(new ScopeSet())->expectedIssuer);
    }

    public function testTheRedirectRecordsTheRequestedScopes(): void
    {
        self::assertSame(['files:read'], $this->authorize(new ScopeSet(['files:read']))->requestedScopes->values);
    }

    public function testTheRedirectRequiresTheIssuerParameterWhenTheServerAdvertisesIt(): void
    {
        self::assertTrue($this->authorize(new ScopeSet(), issuerParameterSupported: true)->issuerParameterRequired);
    }

    public function testTheRedirectDoesNotRequireTheIssuerParameterWhenTheServerDeniesIt(): void
    {
        self::assertFalse($this->authorize(new ScopeSet(), issuerParameterSupported: false)->issuerParameterRequired);
    }

    public function testTheRedirectDoesNotRequireTheIssuerParameterWhenTheServerIsSilent(): void
    {
        self::assertFalse($this->authorize(new ScopeSet())->issuerParameterRequired);
    }

    public function testTheRedirectCarriesAFreshStateAndVerifierEachTime(): void
    {
        $first = $this->authorize(new ScopeSet());
        $second = $this->authorize(new ScopeSet());

        self::assertNotSame($first->state, $second->state);
        self::assertNotSame($first->pkce->verifier, $second->pkce->verifier);
        self::assertSame(64, \strlen($first->state));
    }

    public function testTheRedirectDerivesTheChallengeFromTheVerifierItRecorded(): void
    {
        $redirect = $this->authorize(new ScopeSet());

        self::assertSame(PkcePair::fromVerifier($redirect->pkce->verifier)->challenge, $redirect->pkce->challenge);
    }

    public function testGrantRejectsAServerWithNoAuthorizationEndpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" publishes no authorization endpoint.');

        $this->authorize(new ScopeSet(), null);
    }

    /**
     * @param non-empty-string $endpoint
     */
    #[DataProvider('provideGrantRefusesAnUntrustedAuthorizationEndpointCases')]
    public function testGrantRefusesAnUntrustedAuthorizationEndpoint(string $endpoint, string $message): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs($message);

        $this->authorize(new ScopeSet(), $endpoint);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideGrantRefusesAnUntrustedAuthorizationEndpointCases(): iterable
    {
        yield 'an insecure endpoint' => [
            'http://auth.example.com/authorize',
            'The authorization metadata cannot be trusted because the authorization endpoint "http://auth.example.com/authorize" is not an absolute HTTPS URL.',
        ];

        yield 'a loopback endpoint without the opt-in' => [
            'http://127.0.0.1:9000/authorize',
            'The authorization metadata cannot be trusted because the authorization endpoint "http://127.0.0.1:9000/authorize" is not an absolute HTTPS URL.',
        ];

        yield 'a non-HTTP endpoint' => [
            'javascript:alert(1)//',
            'The authorization metadata cannot be trusted because the authorization endpoint "javascript:alert(1)//" is not an absolute HTTPS URL.',
        ];

        yield 'an endpoint carrying a fragment' => [
            'https://auth.example.com/authorize#done',
            'The authorization metadata cannot be trusted because the authorization endpoint "https://auth.example.com/authorize#done" carries a fragment.',
        ];
    }

    public function testGrantExchangesTheCodeTheAuthorizationResponseCarries(): void
    {
        $form = $this->exchange(new ScriptedUserAuthorization(['code' => 'abc123']));

        self::assertSame('abc123', $form['code'] ?? null);
    }

    public function testGrantAcceptsAMatchingIssuer(): void
    {
        $form = $this->exchange(
            new ScriptedUserAuthorization(['code' => 'abc123', 'iss' => self::ISSUER]),
            issuerParameterSupported: true,
        );

        self::assertSame('abc123', $form['code'] ?? null);
    }

    public function testGrantAcceptsAMatchingIssuerTheServerDidNotAdvertise(): void
    {
        $form = $this->exchange(new ScriptedUserAuthorization(['code' => 'abc123', 'iss' => self::ISSUER]));

        self::assertSame('abc123', $form['code'] ?? null);
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('provideGrantRejectsAStateMismatchCases')]
    public function testGrantRejectsAStateMismatch(array $parameters): void
    {
        $this->expectException(InvalidAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The authorization response cannot be used because its "state" does not echo the authorization request.');

        $this->exchange(new RawCallbackUserAuthorization($parameters));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function provideGrantRejectsAStateMismatchCases(): iterable
    {
        yield 'a different state' => [['code' => 'abc123', 'state' => 'another-state']];

        yield 'an absent state' => [['code' => 'abc123']];

        yield 'an empty state' => [['code' => 'abc123', 'state' => '']];
    }

    public function testGrantRejectsAnAbsentIssuerTheServerAdvertises(): void
    {
        $this->expectException(InvalidAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The authorization response cannot be used because it omits the "iss" the authorization server advertises that it emits.');

        $this->exchange(new ScriptedUserAuthorization(['code' => 'abc123']), issuerParameterSupported: true);
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('provideGrantRejectsAnIssuerMismatchCases')]
    public function testGrantRejectsAnIssuerMismatch(array $parameters, bool $issuerParameterSupported): void
    {
        $this->expectException(InvalidAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The authorization response cannot be used because its "iss" names an authorization server other than the one the request was sent to.');

        $this->exchange(new ScriptedUserAuthorization($parameters), issuerParameterSupported: $issuerParameterSupported);
    }

    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function provideGrantRejectsAnIssuerMismatchCases(): iterable
    {
        yield 'a different issuer when the parameter is advertised' => [
            ['code' => 'abc123', 'iss' => 'https://attacker.example'],
            true,
        ];

        yield 'a different issuer when the parameter is not advertised' => [
            ['code' => 'abc123', 'iss' => 'https://attacker.example'],
            false,
        ];

        yield 'a trailing slash is not normalised away' => [
            ['code' => 'abc123', 'iss' => 'https://auth.example.com/'],
            false,
        ];

        yield 'host case is not folded' => [
            ['code' => 'abc123', 'iss' => 'https://AUTH.example.com'],
            false,
        ];

        yield 'scheme case is not folded' => [
            ['code' => 'abc123', 'iss' => 'HTTPS://auth.example.com'],
            false,
        ];

        yield 'an explicit default port is not elided' => [
            ['code' => 'abc123', 'iss' => 'https://auth.example.com:443'],
            false,
        ];
    }

    public function testGrantRejectsAMismatchedIssuerBeforeSurfacingTheError(): void
    {
        $this->expectException(InvalidAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The authorization response cannot be used because its "iss" names an authorization server other than the one the request was sent to.');

        $this->exchange(new ScriptedUserAuthorization([
            'iss' => 'https://attacker.example',
            'error' => 'access_denied',
            'error_description' => 'Attacker-supplied text that must not surface.',
        ]));
    }

    public function testGrantSurfacesTheErrorWithItsDescription(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The authorization server denied the request with "access_denied": The user refused consent.');

        $this->exchange(new ScriptedUserAuthorization([
            'error' => 'access_denied',
            'error_description' => 'The user refused consent.',
        ]));
    }

    public function testGrantSurfacesAnErrorWithNoDescription(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The authorization server denied the request with "invalid_scope".');

        $this->exchange(new ScriptedUserAuthorization(['error' => 'invalid_scope']));
    }

    public function testGrantRejectsAResponseWithNoCode(): void
    {
        $this->expectException(InvalidAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The authorization response cannot be used because it carries no authorization code.');

        $this->exchange(new ScriptedUserAuthorization([]));
    }

    /**
     * @param null|list<non-empty-string> $methods
     * @param null|non-empty-string       $authorizationEndpoint
     */
    private function buildContext(
        RecordingHttpClient $http,
        ScopeSet $scopes,
        ?AuthorizationOptions $options = null,
        ?array $methods = ['S256'],
        ?string $authorizationEndpoint = self::AUTHORIZATION_ENDPOINT,
        ?bool $issuerParameterSupported = null,
    ): GrantContext {
        $resource = new ResourceIdentifier(self::RESOURCE);

        return new GrantContext(
            new DiscoveredResource(
                new ProtectedResourceMetadata($resource, [self::ISSUER]),
                new AuthorizationServerMetadata(
                    self::ISSUER,
                    authorizationEndpoint: $authorizationEndpoint,
                    tokenEndpoint: 'https://auth.example.com/token',
                    registrationEndpoint: 'https://auth.example.com/register',
                    codeChallengeMethodsSupported: $methods,
                    authorizationResponseIssParameterSupported: $issuerParameterSupported,
                ),
            ),
            $resource,
            $scopes,
            $options ?? new AuthorizationOptions('Example MCP Client', 'http://localhost:3000/callback'),
            $http,
            new ArrayLogger(),
            new ClientRegistrar($http, new InMemoryClientRegistrationStore()),
            new TokenEndpoint($http),
        );
    }

    private function buildPreRegisteredOptions(bool $skipOfflineAccessConsent = false): AuthorizationOptions
    {
        return new AuthorizationOptions(
            'Example MCP Client',
            'http://localhost:3000/callback',
            preRegistered: new ClientRegistration('https://app.example.com/client.json'),
            skipOfflineAccessConsent: $skipOfflineAccessConsent,
        );
    }

    /**
     * Runs the grant for a pre-registered client and returns the redirect handed to the user.
     *
     * @param null|non-empty-string $authorizationEndpoint
     */
    private function authorize(
        ScopeSet $scopes,
        ?string $authorizationEndpoint = self::AUTHORIZATION_ENDPOINT,
        ?bool $issuerParameterSupported = null,
        ?AuthorizationOptions $options = null,
    ): AuthorizationRedirect {
        $http = (new RecordingHttpClient())
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
        ;
        $user = new ScriptedUserAuthorization(['code' => 'the-code', 'iss' => self::ISSUER]);

        (new AuthorizationCodeGrantStrategy($user))->grant(
            $this->buildContext(
                $http,
                $scopes,
                $options ?? $this->buildPreRegisteredOptions(),
                authorizationEndpoint: $authorizationEndpoint,
                issuerParameterSupported: $issuerParameterSupported,
            ),
            new NullCancellation(),
        );

        return $user->readRedirect();
    }

    /**
     * Runs the grant for a pre-registered client and returns the token request's form.
     *
     * @return array<array-key, string>
     */
    private function exchange(UserAuthorizationInterface $user, ?bool $issuerParameterSupported = null): array
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson(['access_token' => 'the-access-token', 'token_type' => 'Bearer'])
        ;

        (new AuthorizationCodeGrantStrategy($user))->grant(
            $this->buildContext(
                $http,
                new ScopeSet(),
                $this->buildPreRegisteredOptions(),
                issuerParameterSupported: $issuerParameterSupported,
            ),
            new NullCancellation(),
        );

        return $this->readForm($http->readRequest(0));
    }

    /**
     * @return array<array-key, string>
     */
    private function readForm(Request $request): array
    {
        parse_str(buffer($request->getBody()->getContent()), $parsed);

        $parameters = [];

        foreach ($parsed as $name => $value) {
            if (\is_string($value)) {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }

    /**
     * @return array<string, string>
     */
    private function readQuery(string $url): array
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
