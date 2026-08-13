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

namespace Nexus\Mcp\Tests\Server\Transport\Http\Middleware;

use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Auth\WwwAuthenticateChallenge;
use Nexus\Mcp\Server\Auth\AccessTokenValidatorInterface;
use Nexus\Mcp\Server\Transport\Http\Middleware\BearerAuthenticationMiddleware;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
#[CoversClass(BearerAuthenticationMiddleware::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class BearerAuthenticationMiddlewareTest extends AbstractMcpTestCase
{
    private const string RESOURCE = 'https://mcp.test/mcp';
    private const string METADATA_URL = 'https://mcp.test/.well-known/oauth-protected-resource/mcp';

    public function testAValidTokenReachesTheHandler(): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(self::request('the-token'), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->called);
    }

    #[DataProvider('provideAnExpiredTokenIsRefusedCases')]
    public function testAnExpiredTokenIsRefused(int $expiresAt): void
    {
        $handler = self::handler();
        $token = new VerifiedAccessToken([self::RESOURCE], subject: 'the-subject', expiresAt: $expiresAt);

        $response = self::middleware($token, now: 1_000)->process(self::request('the-token'), $handler);

        self::assertFalse($handler->called);
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function provideAnExpiredTokenIsRefusedCases(): iterable
    {
        yield 'long past' => [1];

        // RFC 7519 refuses a token on or after its expiry, so the exact second is already too late.
        yield 'exactly now' => [1_000];
    }

    public function testATokenExpiringInTheNextSecondIsStillAccepted(): void
    {
        $handler = self::handler();
        $token = new VerifiedAccessToken([self::RESOURCE], subject: 'the-subject', expiresAt: 1_001);

        $response = self::middleware($token, now: 1_000)->process(self::request('the-token'), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testConfiguredLeewayToleratesATokenExpiredWithinIt(): void
    {
        $handler = self::handler();
        $token = new VerifiedAccessToken([self::RESOURCE], subject: 'the-subject', expiresAt: 940);

        $response = self::middleware($token, now: 1_000, expiryLeewaySeconds: 300)
            ->process(self::request('the-token'), $handler)
        ;

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testConfiguredLeewayStillRefusesATokenExpiredBeyondIt(): void
    {
        $handler = self::handler();
        $token = new VerifiedAccessToken([self::RESOURCE], subject: 'the-subject', expiresAt: 699);

        $response = self::middleware($token, now: 1_000, expiryLeewaySeconds: 300)
            ->process(self::request('the-token'), $handler)
        ;

        self::assertFalse($handler->called);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testTheDefaultToleratesNoClockSkew(): void
    {
        $handler = self::handler();
        $recognised = new VerifiedAccessToken([self::RESOURCE], subject: 'the-subject', expiresAt: 1_000);
        $validator = self::createStub(AccessTokenValidatorInterface::class);
        $validator->method('validate')->willReturn($recognised);

        $middleware = new BearerAuthenticationMiddleware(
            $validator,
            self::RESOURCE,
            self::METADATA_URL,
            new Psr17Factory(),
            clock: static fn(): int => 1_000,
        );

        $response = $middleware->process(self::request('the-token'), $handler);

        self::assertFalse($handler->called);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testANegativeExpiryLeewayIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Expiry leeway must be a non-negative integer, -1 given.');

        new BearerAuthenticationMiddleware(
            self::createStub(AccessTokenValidatorInterface::class),
            self::RESOURCE,
            self::METADATA_URL,
            new Psr17Factory(),
            // @phpstan-ignore argument.type
            expiryLeewaySeconds: -1,
        );
    }

    public function testATokenReportingNoExpiryIsAccepted(): void
    {
        $handler = self::handler();
        $token = new VerifiedAccessToken([self::RESOURCE], subject: 'the-subject');

        $response = self::middleware($token, now: 1_000)->process(self::request('the-token'), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testTheValidatedTokenTravelsOnTheRequest(): void
    {
        $handler = self::handler();

        self::middleware()->process(self::request('the-token'), $handler);

        $token = $handler->received?->getAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE);

        self::assertInstanceOf(VerifiedAccessToken::class, $token);

        self::assertSame('the-subject', $token->subject);
    }

    public function testARequestCarryingNoCredentialsIsChallenged(): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(self::request(null), $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertSame(
            ['resource_metadata' => self::METADATA_URL],
            self::readChallenge($response),
        );
    }

    #[DataProvider('provideARequestPresentingNoBearerCredentialIsChallengedWithoutAnErrorCodeCases')]
    public function testARequestPresentingNoBearerCredentialIsChallengedWithoutAnErrorCode(string $header): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(self::request(null)->withHeader('Authorization', $header), $handler);

        // RFC 6750 section 3 asks that neither an unsupported authentication method nor a credential-less request be told an error code.
        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertSame(['resource_metadata' => self::METADATA_URL], self::readChallenge($response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideARequestPresentingNoBearerCredentialIsChallengedWithoutAnErrorCodeCases(): iterable
    {
        yield 'an empty Authorization header' => [''];

        yield 'another scheme' => ['Basic dXNlcjpwYXNz'];

        yield 'another scheme carrying no credential' => ['Negotiate'];

        yield 'a scheme merely starting with the word' => ['BearerToken abc'];
    }

    #[DataProvider('provideAnUnreadableAuthorizationHeaderIsAnInvalidRequestCases')]
    public function testAnUnreadableAuthorizationHeaderIsAnInvalidRequest(string $header): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(self::request(null)->withHeader('Authorization', $header), $handler);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertSame(
            ['resource_metadata' => self::METADATA_URL, 'error' => 'invalid_request'],
            self::readChallenge($response),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnUnreadableAuthorizationHeaderIsAnInvalidRequestCases(): iterable
    {
        yield 'the scheme with no token' => ['Bearer '];

        yield 'the scheme with only spaces' => ['Bearer    '];

        yield 'the scheme and nothing after it' => ['Bearer'];

        yield 'the scheme cased differently and carrying no token' => ['BEARER  '];
    }

    #[DataProvider('provideTheSchemeIsMatchedCaseInsensitivelyCases')]
    public function testTheSchemeIsMatchedCaseInsensitively(string $header): void
    {
        $handler = self::handler();

        self::middleware()->process(self::request(null)->withHeader('Authorization', $header), $handler);

        self::assertTrue($handler->called);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheSchemeIsMatchedCaseInsensitivelyCases(): iterable
    {
        yield 'canonical' => ['Bearer the-token'];

        yield 'lowercase' => ['bearer the-token'];

        yield 'uppercase' => ['BEARER the-token'];
    }

    public function testRepeatedAuthorizationHeadersAreRefused(): void
    {
        $handler = self::handler();
        $request = self::request(null)
            ->withHeader('Authorization', 'Bearer the-token')
            ->withAddedHeader('Authorization', 'Bearer another-token')
        ;

        $response = self::middleware()->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($handler->called);
    }

    public function testABearerCredentialSmuggledBesideAnotherSchemeIsRefused(): void
    {
        $handler = self::handler();
        $request = self::request(null)
            ->withHeader('Authorization', 'Basic dXNlcjpwYXNz')
            ->withAddedHeader('Authorization', 'Bearer the-token')
        ;

        $response = self::middleware()->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertSame(
            ['resource_metadata' => self::METADATA_URL, 'error' => 'invalid_request'],
            self::readChallenge($response),
        );
    }

    public function testAnUnrecognisedTokenIsChallengedAsInvalid(): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(self::request('some-other-token'), $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertSame(
            ['resource_metadata' => self::METADATA_URL, 'error' => 'invalid_token'],
            self::readChallenge($response),
        );
    }

    /**
     * @param list<string> $audience
     */
    #[DataProvider('provideATokenForAnotherResourceIsRefusedCases')]
    public function testATokenForAnotherResourceIsRefused(array $audience): void
    {
        $handler = self::handler();
        $middleware = self::middleware(token: new VerifiedAccessToken($audience));

        $response = $middleware->process(self::request('the-token'), $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertSame(
            ['resource_metadata' => self::METADATA_URL, 'error' => 'invalid_token'],
            self::readChallenge($response),
        );
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideATokenForAnotherResourceIsRefusedCases(): iterable
    {
        yield 'an empty audience' => [[]];

        yield 'another server' => [['https://other.test/mcp']];

        yield 'the same host at another path' => [['https://mcp.test/other']];
    }

    public function testAnAudienceNamingThisServerAmongOthersIsAccepted(): void
    {
        $handler = self::handler();
        $middleware = self::middleware(token: new VerifiedAccessToken(['https://other.test/mcp', self::RESOURCE]));

        $middleware->process(self::request('the-token'), $handler);

        self::assertTrue($handler->called);
    }

    public function testATooNarrowTokenIsChallengedForScope(): void
    {
        $handler = self::handler();
        $middleware = self::middleware(
            token: new VerifiedAccessToken([self::RESOURCE], ['files:read']),
            requiredScopes: ['files:read', 'files:write'],
        );

        $response = $middleware->process(self::request('the-token'), $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertSame([
            'resource_metadata' => self::METADATA_URL,
            'error' => 'insufficient_scope',
            'scope' => 'files:read files:write',
        ], self::readChallenge($response));
    }

    public function testASufficientlyScopedTokenReachesTheHandler(): void
    {
        $handler = self::handler();
        $middleware = self::middleware(
            token: new VerifiedAccessToken([self::RESOURCE], ['files:read', 'files:write', 'files:admin']),
            requiredScopes: ['files:read', 'files:write'],
        );

        $middleware->process(self::request('the-token'), $handler);

        self::assertTrue($handler->called);
    }

    public function testTheRequiredScopesAreAdvertisedOnTheUnauthorizedChallenge(): void
    {
        $response = self::middleware(requiredScopes: ['files:read'])->process(self::request(null), self::handler());

        self::assertSame([
            'resource_metadata' => self::METADATA_URL,
            'scope' => 'files:read',
        ], self::readChallenge($response));
    }

    public function testTheTokenIsPresentedToTheValidatorVerbatim(): void
    {
        $presented = [];
        $validator = self::createStub(AccessTokenValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(
            static function (string $token) use (&$presented): ?VerifiedAccessToken {
                $presented[] = $token;

                return null;
            },
        );

        (new BearerAuthenticationMiddleware($validator, self::RESOURCE, self::METADATA_URL, new Psr17Factory()))
            ->process(self::request(null)->withHeader('Authorization', 'Bearer   Padded-Token  '), self::handler())
        ;

        self::assertSame(['Padded-Token'], $presented);
    }

    /**
     * @param list<non-empty-string> $requiredScopes
     */
    private static function middleware(
        ?VerifiedAccessToken $token = null,
        array $requiredScopes = [],
        ?int $now = null,
        int $expiryLeewaySeconds = 0,
    ): BearerAuthenticationMiddleware {
        \assert($expiryLeewaySeconds >= 0);

        $recognised = $token ?? new VerifiedAccessToken([self::RESOURCE], subject: 'the-subject');
        $validator = self::createStub(AccessTokenValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(
            static fn(string $presented): ?VerifiedAccessToken => 'the-token' === $presented ? $recognised : null,
        );

        return new BearerAuthenticationMiddleware(
            $validator,
            self::RESOURCE,
            self::METADATA_URL,
            new Psr17Factory(),
            $requiredScopes,
            $expiryLeewaySeconds,
            null === $now ? null : static fn(): int => $now,
        );
    }

    private static function request(?string $token): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', self::RESOURCE);

        return null === $token ? $request : $request->withHeader('Authorization', 'Bearer '.$token);
    }

    private static function handler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler((new Psr17Factory())->createResponse(200));
    }

    /**
     * @return array<non-empty-string, string>
     */
    private static function readChallenge(ResponseInterface $response): array
    {
        $challenge = WwwAuthenticateChallenge::findBearer($response->getHeaderLine('WWW-Authenticate'));

        self::assertInstanceOf(WwwAuthenticateChallenge::class, $challenge);

        return $challenge->parameters;
    }
}
