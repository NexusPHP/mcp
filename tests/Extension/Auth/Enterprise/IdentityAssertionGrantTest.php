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

namespace Nexus\Mcp\Tests\Extension\Auth\Enterprise;

use Amp\Cancellation;
use Amp\Http\Client\Request;
use Amp\NullCancellation;
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\ClientRegistrar;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Auth\DiscoveredResource;
use Nexus\Mcp\Client\Auth\GrantContext;
use Nexus\Mcp\Client\Auth\InMemoryClientRegistrationStore;
use Nexus\Mcp\Client\Auth\TokenEndpoint;
use Nexus\Mcp\Client\Exception\UntrustedAuthorizationMetadataException;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Auth\ProtectedResourceMetadata;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Core\Auth\TokenEndpointAuthMethod;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertion;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionGrant;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionProviderInterface;
use Nexus\Mcp\Extension\Auth\Enterprise\IdentityAssertionType;
use Nexus\Mcp\Extension\Auth\Exception\UnsupportedClientAuthenticationException;
use Nexus\Mcp\Extension\Auth\Exception\UnsupportedGrantException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

use function Amp\ByteStream\buffer;

/**
 * @internal
 */
#[CoversClass(IdentityAssertionGrant::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class IdentityAssertionGrantTest extends AbstractMcpTestCase
{
    private const string IDP_ENDPOINT = 'https://idp.example.com/token';
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string ISSUER = 'https://auth.example.com';

    public function testGrantExchangesTheAssertionAndRedeemsTheIdJag(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson(self::exchangeResponse())
            ->willAnswerJson(self::tokenResponse())
        ;
        $logger = new ArrayLogger();
        $grant = new IdentityAssertionGrant(self::IDP_ENDPOINT, self::provider(), 'the-idp-client');

        $token = $grant->grant(self::context($http, self::metadata(), self::preRegisteredOptions(), logger: $logger), new NullCancellation());

        self::assertSame('the-access-token', $token->value);
        self::assertSame(self::ISSUER, $token->issuer);

        $exchange = $http->readRequest(0);
        self::assertSame(self::IDP_ENDPOINT, (string) $exchange->getUri());
        self::assertSame([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'requested_token_type' => 'urn:ietf:params:oauth:token-type:id-jag',
            'subject_token' => 'the-id-token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'audience' => self::ISSUER,
            'resource' => self::RESOURCE,
            'client_id' => 'the-idp-client',
        ], self::readForm($exchange));

        $redemption = $http->readRequest(1);
        self::assertSame('https://auth.example.com/token', (string) $redemption->getUri());
        self::assertSame('Basic '.base64_encode('the-client:the-secret'), $redemption->getHeader('Authorization'));
        self::assertSame([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => 'the-id-jag',
            'resource' => self::RESOURCE,
        ], self::readForm($redemption));

        $records = $logger->recordsMatching(LogLevel::INFO, 'The authorization server {issuer} publishes no authorization grant profiles, so ID-JAG support is taken on trust.');
        self::assertCount(1, $records);
        self::assertSame(['issuer' => self::ISSUER], $records[0]['context']);
    }

    public function testGrantAsksForTheSelectedScopes(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson(self::exchangeResponse())
            ->willAnswerJson(self::tokenResponse())
        ;
        $grant = new IdentityAssertionGrant(self::IDP_ENDPOINT, self::provider());

        $token = $grant->grant(
            self::context($http, self::metadata(), self::preRegisteredOptions(), new ScopeSet(['files:read'])),
            new NullCancellation(),
        );

        self::assertSame(['files:read'], $token->scopes);
        self::assertSame('files:read', self::readForm($http->readRequest(1))['scope'] ?? null);
    }

    public function testAGrantTypeListWithoutJwtBearerIsRefused(): void
    {
        $grant = new IdentityAssertionGrant(self::IDP_ENDPOINT, self::provider());

        $this->expectException(UnsupportedGrantException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not advertise the "urn:ietf:params:oauth:grant-type:jwt-bearer" grant type.');

        $grant->grant(
            self::context(new RecordingHttpClient(), self::metadata(grantTypes: ['client_credentials']), self::preRegisteredOptions()),
            new NullCancellation(),
        );
    }

    public function testAProfileListWithoutIdJagIsRefused(): void
    {
        $grant = new IdentityAssertionGrant(self::IDP_ENDPOINT, self::provider());

        $this->expectException(UnsupportedGrantException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not advertise the "urn:ietf:params:oauth:grant-profile:id-jag" authorization grant profile.');

        $grant->grant(
            self::context(new RecordingHttpClient(), self::metadata(profiles: ['urn:example:other-profile']), self::preRegisteredOptions()),
            new NullCancellation(),
        );
    }

    public function testAProfileListNamingIdJagProceedsWithoutLogging(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson(self::exchangeResponse())
            ->willAnswerJson(self::tokenResponse())
        ;
        $logger = new ArrayLogger();
        $grant = new IdentityAssertionGrant(self::IDP_ENDPOINT, self::provider());

        $token = $grant->grant(
            self::context($http, self::metadata(profiles: ['urn:ietf:params:oauth:grant-profile:id-jag']), self::preRegisteredOptions(), logger: $logger),
            new NullCancellation(),
        );

        self::assertSame('the-access-token', $token->value);
        self::assertSame([], $logger->records);
    }

    public function testMissingCredentialsAreRefusedBeforeAnythingIsSent(): void
    {
        $http = new RecordingHttpClient();
        $grant = new IdentityAssertionGrant(self::IDP_ENDPOINT, self::provider());

        try {
            $grant->grant(self::context($http, self::metadata(), new AuthorizationOptions('Example MCP Client')), new NullCancellation());
            self::fail('The grant should have been refused.');
        } catch (UnsupportedClientAuthenticationException $e) {
            self::assertSame(
                'Enterprise-managed authorization needs pre-registered credentials or a Client ID Metadata Document URL, and the authorization options carry neither.',
                $e->getMessage(),
            );
            self::assertSame([], $http->requests);
        }
    }

    public function testACimdUrlNeedsTheServersSupport(): void
    {
        $grant = new IdentityAssertionGrant(self::IDP_ENDPOINT, self::provider());

        $this->expectException(UnsupportedClientAuthenticationException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not support Client ID Metadata Documents.');

        $grant->grant(
            self::context(new RecordingHttpClient(), self::metadata(), self::cimdOptions()),
            new NullCancellation(),
        );
    }

    public function testACimdUrlServesAsTheClientId(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson(self::exchangeResponse())
            ->willAnswerJson(self::tokenResponse())
        ;
        $grant = new IdentityAssertionGrant(self::IDP_ENDPOINT, self::provider());

        $token = $grant->grant(
            self::context($http, self::metadata(clientIdMetadataDocumentSupported: true), self::cimdOptions()),
            new NullCancellation(),
        );

        self::assertSame('the-access-token', $token->value);

        $redemption = $http->readRequest(1);
        self::assertNull($redemption->getHeader('Authorization'));
        self::assertSame([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => 'the-id-jag',
            'resource' => self::RESOURCE,
            'client_id' => 'https://app.example.com/client.json',
        ], self::readForm($redemption));
    }

    public function testItRenewsByAFreshGrant(): void
    {
        self::assertTrue((new IdentityAssertionGrant(self::IDP_ENDPOINT, self::provider()))->renewsByFreshGrant());
    }

    public function testAnEmptyIdpTokenEndpointIsRefused(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"idpTokenEndpoint" must be a non-empty string.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new IdentityAssertionGrant('', self::provider());
    }

    public function testACleartextIdpEndpointIsRefusedAtConstruction(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the IdP token endpoint "http://idp.example.com/token" is not an absolute HTTPS URL.');

        new IdentityAssertionGrant('http://idp.example.com/token', self::provider());
    }

    public function testACleartextLoopbackIdpEndpointIsAdmittedOnlyByOptingIn(): void
    {
        $this->expectNotToPerformAssertions();

        new IdentityAssertionGrant('http://127.0.0.1:1/token', self::provider(), allowInsecureLoopback: true);
    }

    public function testACleartextLoopbackIdpEndpointIsRefusedWithoutOptingIn(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the IdP token endpoint "http://127.0.0.1:1/token" is not an absolute HTTPS URL.');

        new IdentityAssertionGrant('http://127.0.0.1:1/token', self::provider());
    }

    public function testAnEmptyIdpClientIdIsRefused(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"idpClientId" must be a non-empty string or null.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        new IdentityAssertionGrant(self::IDP_ENDPOINT, self::provider(), '');
    }

    private static function provider(): IdentityAssertionProviderInterface
    {
        return new class implements IdentityAssertionProviderInterface {
            #[\Override]
            public function provideAssertion(Cancellation $cancellation): IdentityAssertion
            {
                return new IdentityAssertion('the-id-token', IdentityAssertionType::IdToken);
            }
        };
    }

    private static function preRegisteredOptions(): AuthorizationOptions
    {
        return new AuthorizationOptions(
            'Example MCP Client',
            preRegistered: new ClientRegistration('the-client', null, 'the-secret', TokenEndpointAuthMethod::ClientSecretBasic),
        );
    }

    private static function cimdOptions(): AuthorizationOptions
    {
        return new AuthorizationOptions('Example MCP Client', clientIdMetadataDocumentUrl: 'https://app.example.com/client.json');
    }

    /**
     * @param null|list<non-empty-string> $grantTypes
     * @param null|list<non-empty-string> $profiles
     */
    private static function metadata(
        ?array $grantTypes = null,
        ?array $profiles = null,
        ?bool $clientIdMetadataDocumentSupported = null,
    ): AuthorizationServerMetadata {
        return new AuthorizationServerMetadata(
            self::ISSUER,
            tokenEndpoint: 'https://auth.example.com/token',
            clientIdMetadataDocumentSupported: $clientIdMetadataDocumentSupported,
            grantTypesSupported: $grantTypes,
            authorizationGrantProfilesSupported: $profiles,
        );
    }

    private static function context(
        RecordingHttpClient $http,
        AuthorizationServerMetadata $server,
        AuthorizationOptions $options,
        ScopeSet $scopes = new ScopeSet(),
        ?ArrayLogger $logger = null,
    ): GrantContext {
        $resource = new ResourceIdentifier(self::RESOURCE);

        return new GrantContext(
            new DiscoveredResource(new ProtectedResourceMetadata($resource, [self::ISSUER]), $server),
            $resource,
            $scopes,
            $options,
            $http,
            $logger ?? new ArrayLogger(),
            new ClientRegistrar($http, new InMemoryClientRegistrationStore()),
            new TokenEndpoint($http),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function exchangeResponse(array $overrides = []): array
    {
        return [
            'access_token' => 'the-id-jag',
            'issued_token_type' => 'urn:ietf:params:oauth:token-type:id-jag',
            'token_type' => 'N_A',
            ...$overrides,
        ];
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
