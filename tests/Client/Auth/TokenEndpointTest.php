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
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Client\Auth\AccessToken;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Auth\TokenEndpoint;
use Nexus\Mcp\Client\Exception\AuthorizationGrantRejectedException;
use Nexus\Mcp\Client\Exception\ClientRegistrationRejectedException;
use Nexus\Mcp\Client\Exception\MalformedAuthorizationResponseException;
use Nexus\Mcp\Client\Exception\TokenRequestFailedException;
use Nexus\Mcp\Client\Exception\UntrustedAuthorizationMetadataException;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Core\Auth\TokenEndpointAuthMethod;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function Amp\ByteStream\buffer;

/**
 * @internal
 */
#[CoversClass(TokenEndpoint::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class TokenEndpointTest extends AbstractMcpTestCase
{
    private const string ISSUER = 'https://auth.example.com';
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string REDIRECT_URI = 'http://localhost:3000/callback';
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    public function testItPostsTheGrantBodyAsAForm(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        self::exchange($http);

        $request = $http->readRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://auth.example.com/token', (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeader('Content-Type'));
        self::assertSame('application/json', $request->getHeader('Accept'));
        self::assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'the-code',
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
            'resource' => self::RESOURCE,
            'client_id' => 'the-client',
        ], self::readForm($request));
    }

    public function testExchangeCodeReadsTheIssuedToken(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse([
            'refresh_token' => 'the-refresh-token',
            'scope' => 'files:read files:write',
        ]));

        $token = self::exchange($http);

        self::assertSame('the-access-token', $token->value);
        self::assertSame('the-refresh-token', $token->refreshToken);
        self::assertSame(['files:read', 'files:write'], $token->scopes);
    }

    public function testExchangeCodeTurnsTheLifetimeIntoAnExpiryTimestamp(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse(['expires_in' => 3_600]));

        $before = time();
        $token = self::exchange($http);

        self::assertNotNull($token->expiresAt);
        self::assertGreaterThanOrEqual($before + 3_600, $token->expiresAt);
        self::assertLessThanOrEqual(time() + 3_600, $token->expiresAt);
    }

    public function testExchangeCodeHoldsAnAbsurdLifetimeToOneTheClockCanCarry(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse(['expires_in' => \PHP_INT_MAX]));

        $before = time();
        $token = self::exchange($http);

        self::assertNotNull($token->expiresAt);
        self::assertGreaterThanOrEqual($before + 315_360_000, $token->expiresAt);
        self::assertLessThanOrEqual(time() + 315_360_000, $token->expiresAt);
    }

    public function testExchangeCodeLeavesTheExpiryUnknownWhenTheServerNamesNoLifetime(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        self::assertNull(self::exchange($http)->expiresAt);
    }

    public function testExchangeCodeFallsBackToTheRequestedScopesWhenTheResponseNamesNone(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        $token = self::exchange($http, scopes: new ScopeSet(['files:read']));

        self::assertSame(['files:read'], $token->scopes);
    }

    public function testExchangeCodeIssuesNoRefreshTokenWhenTheServerSentNone(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        self::assertNull(self::exchange($http)->refreshToken);
    }

    public function testClientSecretBasicAuthenticatesInTheHeader(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        self::exchange($http, new ClientRegistration('the client', self::ISSUER, 'se cret', TokenEndpointAuthMethod::ClientSecretBasic));

        $request = $http->readRequest();
        self::assertSame('Basic '.base64_encode('the+client:se+cret'), $request->getHeader('Authorization'));
        self::assertArrayNotHasKey('client_id', self::readForm($request));
        self::assertArrayNotHasKey('client_secret', self::readForm($request));
    }

    public function testClientSecretPostAuthenticatesInTheBody(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        self::exchange($http, new ClientRegistration('the-client', self::ISSUER, 'the-secret', TokenEndpointAuthMethod::ClientSecretPost));

        $request = $http->readRequest();
        self::assertNull($request->getHeader('Authorization'));
        self::assertSame('the-client', self::readForm($request)['client_id'] ?? null);
        self::assertSame('the-secret', self::readForm($request)['client_secret'] ?? null);
    }

    public function testTheTimeoutBoundsBothTheTransferAndTheStall(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        self::exchange($http, timeout: 2.5);

        self::assertSame(2.5, $http->readRequest()->getTransferTimeout());
        self::assertSame(2.5, $http->readRequest()->getInactivityTimeout());
    }

    public function testAPublicClientSendsNoSecret(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        self::exchange($http);

        $request = $http->readRequest();
        self::assertNull($request->getHeader('Authorization'));
        self::assertArrayNotHasKey('client_secret', self::readForm($request));
    }

    #[DataProvider('provideASecretBearingMethodRequiresASecretCases')]
    public function testASecretBearingMethodRequiresASecret(TokenEndpointAuthMethod $method): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs(\sprintf('Client "the-client" must carry a secret to authenticate with "%s".', $method->value));

        self::exchange(new RecordingHttpClient(), new ClientRegistration('the-client', self::ISSUER, null, $method));
    }

    /**
     * @return iterable<string, array{TokenEndpointAuthMethod}>
     */
    public static function provideASecretBearingMethodRequiresASecretCases(): iterable
    {
        yield 'client_secret_basic' => [TokenEndpointAuthMethod::ClientSecretBasic];

        yield 'client_secret_post' => [TokenEndpointAuthMethod::ClientSecretPost];
    }

    public function testRefreshSendsTheRefreshTokenGrant(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        (new TokenEndpoint($http))->refresh(
            self::metadata(),
            new ClientRegistration('the-client', self::ISSUER),
            new AccessToken('the-old-token', self::ISSUER, refreshToken: 'the-refresh-token', scopes: ['files:read']),
            new ResourceIdentifier(self::RESOURCE),
            new NullCancellation(),
        );

        self::assertSame([
            'grant_type' => 'refresh_token',
            'refresh_token' => 'the-refresh-token',
            'resource' => self::RESOURCE,
            'client_id' => 'the-client',
        ], self::readForm($http->readRequest()));
    }

    public function testRefreshKeepsTheEarlierScopesWhenTheResponseNamesNone(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        $token = (new TokenEndpoint($http))->refresh(
            self::metadata(),
            new ClientRegistration('the-client', self::ISSUER),
            new AccessToken('the-old-token', self::ISSUER, refreshToken: 'the-refresh-token', scopes: ['files:read']),
            new ResourceIdentifier(self::RESOURCE),
            new NullCancellation(),
        );

        self::assertSame(['files:read'], $token->scopes);
    }

    public function testRefreshKeepsTheEarlierRefreshTokenWhenTheResponseRotatesNone(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        $token = (new TokenEndpoint($http))->refresh(
            self::metadata(),
            new ClientRegistration('the-client', self::ISSUER),
            new AccessToken('the-old-token', self::ISSUER, refreshToken: 'the-refresh-token'),
            new ResourceIdentifier(self::RESOURCE),
            new NullCancellation(),
        );

        self::assertSame('the-refresh-token', $token->refreshToken);
    }

    public function testRefreshTakesTheRotatedRefreshTokenOverTheEarlierOne(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse(['refresh_token' => 'the-rotated-token']));

        $token = (new TokenEndpoint($http))->refresh(
            self::metadata(),
            new ClientRegistration('the-client', self::ISSUER),
            new AccessToken('the-old-token', self::ISSUER, refreshToken: 'the-refresh-token'),
            new ResourceIdentifier(self::RESOURCE),
            new NullCancellation(),
        );

        self::assertSame('the-rotated-token', $token->refreshToken);
    }

    public function testRefreshRejectsATokenWithNoRefreshToken(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('The access token carries no refresh token to redeem.');

        (new TokenEndpoint(new RecordingHttpClient()))->refresh(
            self::metadata(),
            new ClientRegistration('the-client', self::ISSUER),
            new AccessToken('the-old-token', self::ISSUER),
            new ResourceIdentifier(self::RESOURCE),
            new NullCancellation(),
        );
    }

    public function testAnErrorResponseSurfacesTheOAuthError(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(
            ['error' => 'invalid_grant', 'error_description' => 'The code has expired.'],
            400,
        );

        $this->expectException(AuthorizationGrantRejectedException::class);
        $this->expectExceptionMessageIs('The token request failed with "invalid_grant": The code has expired.');

        self::exchange($http);
    }

    public function testAnErrorStatusWithABodyThatIsNotJsonIsNotReadAsARefusedGrant(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson('<html>Bad Gateway</html>', 502);

        $this->expectException(TokenRequestFailedException::class);
        $this->expectExceptionMessageIs('The token request failed with "invalid_request": The token endpoint answered 502 with a body that is not a JSON object.');

        self::exchange($http);
    }

    public function testASuccessWithABodyThatIsNotJsonIsStillMalformed(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson('<html>All good, honest</html>');

        $this->expectException(MalformedAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The token endpoint answered with a payload that is not a JSON object.');

        self::exchange($http);
    }

    public function testAnErrorDescriptionCannotForgeALogRecord(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(
            ['error' => 'invalid_grant', 'error_description' => "Expired.\r\n[2026-07-28] CRITICAL: approved"],
            400,
        );

        $this->expectException(AuthorizationGrantRejectedException::class);
        $this->expectExceptionMessageIs('The token request failed with "invalid_grant": Expired.[2026-07-28] CRITICAL: approved');

        self::exchange($http);
    }

    public function testAnErrorResponseWithNoErrorCodeFallsBackToInvalidRequest(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson([], 400);

        $this->expectException(TokenRequestFailedException::class);
        $this->expectExceptionMessageIs('The token request failed with "invalid_request".');

        self::exchange($http);
    }

    public function testAServerErrorIsAlsoATokenRequestFailure(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['error' => 'server_error'], 500);

        $this->expectException(TokenRequestFailedException::class);
        $this->expectExceptionMessageIs('The token request failed with "server_error".');

        self::exchange($http);
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('provideAGrantRejectionIsToldApartFromAFatalFailureCases')]
    public function testAGrantRejectionIsToldApartFromAFatalFailure(string $error, string $expected): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['error' => $error], 400);

        $this->expectException($expected);

        self::exchange($http);
    }

    /**
     * @return iterable<string, array{string, class-string<\Throwable>}>
     */
    public static function provideAGrantRejectionIsToldApartFromAFatalFailureCases(): iterable
    {
        yield 'a revoked or lapsed grant' => ['invalid_grant', AuthorizationGrantRejectedException::class];

        yield 'a scope reaching past the grant' => ['invalid_scope', AuthorizationGrantRejectedException::class];

        yield 'a client the server does not know' => ['invalid_client', ClientRegistrationRejectedException::class];

        yield 'a malformed request' => ['invalid_request', TokenRequestFailedException::class];

        yield 'a server fault' => ['server_error', TokenRequestFailedException::class];
    }

    public function testAnUnknownClientIsToldApartByItsMessage(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(
            ['error' => 'invalid_client', 'error_description' => 'The registration has lapsed.'],
            401,
        );

        $this->expectException(ClientRegistrationRejectedException::class);
        $this->expectExceptionMessageIs('The authorization server does not recognise the client identifier presented to it, so the client must register again: The registration has lapsed.');

        self::exchange($http);
    }

    public function testANonBearerTokenTypeIsRefused(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse(['token_type' => 'DPoP']));

        $this->expectException(TokenRequestFailedException::class);
        $this->expectExceptionMessageIs('The token request failed with "unsupported_token_type": MCP clients can only present bearer tokens, "DPoP" given.');

        self::exchange($http);
    }

    public function testTheTokenTypeIsMatchedCaseInsensitively(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse(['token_type' => 'bearer']));

        self::assertSame('the-access-token', self::exchange($http)->value);
    }

    public function testAResponseWithNoAccessTokenIsRefused(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['token_type' => 'Bearer']);

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Token response must carry a "access_token" value.');

        self::exchange($http);
    }

    public function testAResponseThatIsNotAJsonObjectIsRefused(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson('"not-an-object"');

        $this->expectException(MalformedAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The token endpoint answered with a payload that is not a JSON object.');

        self::exchange($http);
    }

    public function testAServerWithNoTokenEndpointIsRefused(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" publishes no token endpoint.');

        self::exchange(new RecordingHttpClient(), metadata: new AuthorizationServerMetadata(self::ISSUER));
    }

    public function testAnInsecureTokenEndpointIsRefused(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the token endpoint "http://auth.example.com/token" is not an absolute HTTPS URL.');

        self::exchange(new RecordingHttpClient(), metadata: new AuthorizationServerMetadata(
            self::ISSUER,
            tokenEndpoint: 'http://auth.example.com/token',
        ));
    }

    public function testALoopbackTokenEndpointIsRefusedUnlessOptedIn(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the token endpoint "http://127.0.0.1:9000/token" is not an absolute HTTPS URL.');

        self::exchange(new RecordingHttpClient(), metadata: new AuthorizationServerMetadata(
            self::ISSUER,
            tokenEndpoint: 'http://127.0.0.1:9000/token',
        ));
    }

    public function testRequestTokenRedeemsTheCallerBuiltGrant(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        $token = (new TokenEndpoint($http))->requestToken(
            self::metadata(),
            new ClientRegistration('the-client', self::ISSUER, 'the-secret', TokenEndpointAuthMethod::ClientSecretBasic),
            ['grant_type' => 'client_credentials', 'resource' => self::RESOURCE],
            new ScopeSet(['files:read']),
            new NullCancellation(),
        );

        self::assertSame('the-access-token', $token->value);
        self::assertSame(self::ISSUER, $token->issuer);
        self::assertSame(['files:read'], $token->scopes);
        self::assertNull($token->refreshToken);

        $request = $http->readRequest();
        self::assertSame('https://auth.example.com/token', (string) $request->getUri());
        self::assertNotNull($request->getHeader('Authorization'));
        self::assertSame(['grant_type' => 'client_credentials', 'resource' => self::RESOURCE], self::readForm($request));
    }

    public function testAPrivateKeyJwtClientAuthenticatesThroughItsAssertionAlone(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::tokenResponse());

        (new TokenEndpoint($http))->requestToken(
            self::metadata(),
            new ClientRegistration('the-client', self::ISSUER, null, TokenEndpointAuthMethod::PrivateKeyJwt),
            [
                'grant_type' => 'client_credentials',
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => 'signed.jwt.assertion',
            ],
            new ScopeSet(),
            new NullCancellation(),
        );

        $request = $http->readRequest();
        self::assertNull($request->getHeader('Authorization'));
        self::assertSame([
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => 'signed.jwt.assertion',
        ], self::readForm($request));
    }

    public function testAPrivateKeyJwtClientWithoutAnAssertionIsRefused(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Client "the-client" must carry a "client_assertion" parameter to authenticate with "private_key_jwt".');

        (new TokenEndpoint(new RecordingHttpClient()))->requestToken(
            self::metadata(),
            new ClientRegistration('the-client', self::ISSUER, null, TokenEndpointAuthMethod::PrivateKeyJwt),
            ['grant_type' => 'client_credentials'],
            new ScopeSet(),
            new NullCancellation(),
        );
    }

    private static function exchange(
        RecordingHttpClient $http,
        ?ClientRegistration $registration = null,
        ScopeSet $scopes = new ScopeSet(),
        ?AuthorizationServerMetadata $metadata = null,
        float $timeout = 10.0,
    ): AccessToken {
        return (new TokenEndpoint($http, $timeout))->requestToken(
            $metadata ?? self::metadata(),
            $registration ?? new ClientRegistration('the-client', self::ISSUER),
            [
                'grant_type' => 'authorization_code',
                'code' => 'the-code',
                'redirect_uri' => self::REDIRECT_URI,
                'code_verifier' => self::VERIFIER,
                'resource' => self::RESOURCE,
            ],
            $scopes,
            new NullCancellation(),
        );
    }

    private static function metadata(): AuthorizationServerMetadata
    {
        return new AuthorizationServerMetadata(self::ISSUER, tokenEndpoint: 'https://auth.example.com/token');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function tokenResponse(array $overrides = []): array
    {
        return ['access_token' => 'the-access-token', 'token_type' => 'Bearer', ...$overrides];
    }

    /**
     * @return array<array-key, string>
     */
    private static function readForm(Request $request): array
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
}
