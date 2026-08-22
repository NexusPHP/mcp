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
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\ClientRegistrar;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Auth\InMemoryClientRegistrationStore;
use Nexus\Mcp\Client\Exception\AuthorizationServerMismatchException;
use Nexus\Mcp\Client\Exception\ClientRegistrationRequiredException;
use Nexus\Mcp\Client\Exception\MalformedAuthorizationResponseException;
use Nexus\Mcp\Client\Exception\UntrustedAuthorizationMetadataException;
use Nexus\Mcp\Core\Auth\ApplicationType;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Auth\TokenEndpointAuthMethod;
use Nexus\Mcp\Core\Exception\RuntimeException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function Amp\ByteStream\buffer;

/**
 * @internal
 */
#[CoversClass(ClientRegistrar::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientRegistrarTest extends AbstractMcpTestCase
{
    private const string ISSUER = 'https://auth.example.com';
    private const string CIMD_URL = 'https://app.example.com/oauth/client.json';

    public function testPreRegisteredCredentialsWinOverEveryOtherMechanism(): void
    {
        $preRegistered = new ClientRegistration('pre-registered', self::ISSUER, 'the-secret', TokenEndpointAuthMethod::ClientSecretPost);
        $http = new RecordingHttpClient();

        $registration = $this->resolve($http, $this->metadata(cimdSupported: true, registrationEndpoint: 'https://auth.example.com/register'), $this->options(
            clientIdMetadataDocumentUrl: self::CIMD_URL,
            preRegistered: $preRegistered,
        ));

        self::assertSame($preRegistered, $registration);
        self::assertSame([], $http->requests);
    }

    public function testPreRegisteredCredentialsForAnotherServerAreRefused(): void
    {
        $this->expectException(AuthorizationServerMismatchException::class);
        $this->expectExceptionMessageIs('The supplied client credentials were registered with "https://old.example.com" but the protected resource now names "https://auth.example.com", and credentials are not portable between authorization servers.');

        $this->resolve(new RecordingHttpClient(), $this->metadata(), $this->options(
            preRegistered: new ClientRegistration('pre-registered', 'https://old.example.com'),
        ));
    }

    public function testUnboundCredentialsTakeTheDiscoveredIssuer(): void
    {
        $http = new RecordingHttpClient();

        $registration = $this->resolve($http, $this->metadata(), $this->options(
            preRegistered: new ClientRegistration('pre-registered', clientSecret: 'the-secret'),
        ));

        self::assertSame(self::ISSUER, $registration->issuer);
        self::assertSame('pre-registered', $registration->clientId);
        self::assertSame('the-secret', $registration->clientSecret);
        self::assertSame([], $http->requests, 'Supplied credentials need no registration request.');
    }

    public function testUnboundCredentialsCarryingASecretDefaultToBasicAuthentication(): void
    {
        $registration = $this->resolve(new RecordingHttpClient(), $this->metadata(), $this->options(
            preRegistered: new ClientRegistration('pre-registered', clientSecret: 'the-secret'),
        ));

        self::assertSame(TokenEndpointAuthMethod::ClientSecretBasic, $registration->tokenEndpointAuthMethod);
    }

    public function testUnboundCredentialsWithoutASecretStayUnauthenticated(): void
    {
        $registration = $this->resolve(new RecordingHttpClient(), $this->metadata(), $this->options(
            preRegistered: new ClientRegistration('pre-registered'),
        ));

        self::assertSame(TokenEndpointAuthMethod::None, $registration->tokenEndpointAuthMethod);
    }

    public function testAnExplicitAuthMethodOnUnboundCredentialsIsKept(): void
    {
        $registration = $this->resolve(new RecordingHttpClient(), $this->metadata(), $this->options(
            preRegistered: new ClientRegistration('pre-registered', clientSecret: 'the-secret', tokenEndpointAuthMethod: TokenEndpointAuthMethod::ClientSecretPost),
        ));

        self::assertSame(TokenEndpointAuthMethod::ClientSecretPost, $registration->tokenEndpointAuthMethod);
    }

    public function testAMetadataDocumentUrlIsUsedAsTheClientIdentifier(): void
    {
        $http = new RecordingHttpClient();

        $registration = $this->resolve($http, $this->metadata(cimdSupported: true), $this->options(clientIdMetadataDocumentUrl: self::CIMD_URL));

        self::assertSame(self::CIMD_URL, $registration->clientId);
        self::assertSame(self::ISSUER, $registration->issuer);
        self::assertNull($registration->clientSecret);
        self::assertSame(TokenEndpointAuthMethod::None, $registration->tokenEndpointAuthMethod);
        self::assertSame([], $http->requests);
    }

    public function testAMetadataDocumentUrlIsNotStoredAgainstAnIssuer(): void
    {
        $store = new InMemoryClientRegistrationStore();

        $this->resolve(new RecordingHttpClient(), $this->metadata(cimdSupported: true), $this->options(clientIdMetadataDocumentUrl: self::CIMD_URL), $store);

        self::assertNull($store->read(self::ISSUER));
    }

    #[DataProvider('provideAMetadataDocumentUrlIsSkippedWhenTheServerDoesNotSupportItCases')]
    public function testAMetadataDocumentUrlIsSkippedWhenTheServerDoesNotSupportIt(?bool $cimdSupported, ?string $documentUrl): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['client_id' => 'registered']);

        $registration = $this->resolve(
            $http,
            $this->metadata(cimdSupported: $cimdSupported, registrationEndpoint: 'https://auth.example.com/register'),
            $this->options(clientIdMetadataDocumentUrl: $documentUrl),
        );

        self::assertSame('registered', $registration->clientId);
    }

    /**
     * @return iterable<string, array{null|bool, null|string}>
     */
    public static function provideAMetadataDocumentUrlIsSkippedWhenTheServerDoesNotSupportItCases(): iterable
    {
        yield 'the server denies support' => [false, self::CIMD_URL];

        yield 'the server is silent about support' => [null, self::CIMD_URL];

        yield 'the client hosts no document' => [true, null];
    }

    public function testDynamicRegistrationSendsTheClientMetadata(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['client_id' => 'registered']);

        $this->resolve($http, $this->metadata(registrationEndpoint: 'https://auth.example.com/register'), $this->options());

        $request = $http->readRequest();
        $body = buffer($request->getBody()->getContent());
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://auth.example.com/register', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeader('Content-Type'));
        self::assertSame([
            'client_name' => 'Example MCP Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'application_type' => 'native',
        ], json_decode($body, true, flags: \JSON_THROW_ON_ERROR));
        self::assertStringContainsString(
            '"redirect_uris":["http://localhost:3000/callback"]',
            $body,
            'The URLs the client registers are sent unescaped.',
        );
    }

    public function testDynamicRegistrationDeclaresTheConfiguredApplicationType(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['client_id' => 'registered']);

        $this->resolve($http, $this->metadata(registrationEndpoint: 'https://auth.example.com/register'), $this->options(applicationType: ApplicationType::Web));

        $body = json_decode(buffer($http->readRequest()->getBody()->getContent()), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('web', $body['application_type'] ?? null);
    }

    public function testDynamicRegistrationBindsTheIdentifierToTheIssuer(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['client_id' => 'registered']);

        $registration = $this->resolve($http, $this->metadata(registrationEndpoint: 'https://auth.example.com/register'), $this->options());

        self::assertSame('registered', $registration->clientId);
        self::assertSame(self::ISSUER, $registration->issuer);
    }

    public function testDynamicRegistrationIsStoredAndReusedForTheSameIssuer(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['client_id' => 'registered']);
        $store = new InMemoryClientRegistrationStore();
        $metadata = $this->metadata(registrationEndpoint: 'https://auth.example.com/register');

        $first = $this->resolve($http, $metadata, $this->options(), $store);
        $second = $this->resolve($http, $metadata, $this->options(), $store);

        self::assertSame($first, $second);
        self::assertCount(1, $http->requests);
    }

    public function testAStoredRegistrationIsNotCarriedAcrossAuthorizationServers(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson(['client_id' => 'first'])
            ->willAnswerJson(['client_id' => 'second'])
        ;
        $store = new InMemoryClientRegistrationStore();

        $this->resolve($http, $this->metadata(registrationEndpoint: 'https://auth.example.com/register'), $this->options(), $store);
        $second = $this->resolve(
            $http,
            new AuthorizationServerMetadata('https://other.example.com', registrationEndpoint: 'https://other.example.com/register'),
            $this->options(),
            $store,
        );

        self::assertSame('second', $second->clientId);
        self::assertSame('https://other.example.com', $second->issuer);
        self::assertCount(2, $http->requests);
    }

    public function testAnIssuedSecretDefaultsToBasicAuthentication(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['client_id' => 'registered', 'client_secret' => 'the-secret']);

        $registration = $this->resolve($http, $this->metadata(registrationEndpoint: 'https://auth.example.com/register'), $this->options());

        self::assertSame('the-secret', $registration->clientSecret);
        self::assertSame(TokenEndpointAuthMethod::ClientSecretBasic, $registration->tokenEndpointAuthMethod);
    }

    public function testTheRegisteredAuthenticationMethodIsHonoured(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson([
            'client_id' => 'registered',
            'client_secret' => 'the-secret',
            'token_endpoint_auth_method' => 'client_secret_post',
        ]);

        $registration = $this->resolve($http, $this->metadata(registrationEndpoint: 'https://auth.example.com/register'), $this->options());

        self::assertSame(TokenEndpointAuthMethod::ClientSecretPost, $registration->tokenEndpointAuthMethod);
    }

    #[DataProvider('provideAnUnsupportedAuthenticationMethodIsRefusedCases')]
    public function testAnUnsupportedAuthenticationMethodIsRefused(string $method): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson([
            'client_id' => 'registered',
            'token_endpoint_auth_method' => $method,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(\sprintf('Dynamic Client Registration failed with "invalid_client_metadata": The client was registered with the unsupported "%s" token endpoint authentication method.', $method));

        $this->resolve($http, $this->metadata(registrationEndpoint: 'https://auth.example.com/register'), $this->options());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnUnsupportedAuthenticationMethodIsRefusedCases(): iterable
    {
        yield 'unknown to the SDK' => ['tls_client_auth'];

        yield 'known but keyless in dynamic registration' => ['private_key_jwt'];
    }

    public function testRegistrationWithoutARedirectUriIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Dynamic Client Registration needs a redirect URI, and the authorization options carry none.');

        $this->resolve(
            new RecordingHttpClient(),
            $this->metadata(registrationEndpoint: 'https://auth.example.com/register'),
            new AuthorizationOptions('Example MCP Client'),
        );
    }

    public function testARefusedRegistrationSurfacesTheError(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(
            ['error' => 'invalid_redirect_uri', 'error_description' => 'Loopback redirect URIs are not permitted.'],
            400,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Dynamic Client Registration failed with "invalid_redirect_uri": Loopback redirect URIs are not permitted.');

        $this->resolve($http, $this->metadata(registrationEndpoint: 'https://auth.example.com/register'), $this->options());
    }

    public function testARefusedRegistrationWithNoErrorCodeFallsBackToInvalidClientMetadata(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson([], 400);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Dynamic Client Registration failed with "invalid_client_metadata".');

        $this->resolve($http, $this->metadata(registrationEndpoint: 'https://auth.example.com/register'), $this->options());
    }

    public function testARegistrationResponseWithNoClientIdIsRefused(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Client registration response must carry a "client_id" value.');

        $this->resolve($http, $this->metadata(registrationEndpoint: 'https://auth.example.com/register'), $this->options());
    }

    public function testARegistrationResponseThatIsNotAJsonObjectIsRefused(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson('"not-an-object"');

        $this->expectException(MalformedAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The registration endpoint answered with a payload that is not a JSON object.');

        $this->resolve($http, $this->metadata(registrationEndpoint: 'https://auth.example.com/register'), $this->options());
    }

    public function testAnInsecureRegistrationEndpointIsRefused(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the registration endpoint "http://auth.example.com/register" is not an absolute HTTPS URL.');

        $this->resolve(new RecordingHttpClient(), $this->metadata(registrationEndpoint: 'http://auth.example.com/register'), $this->options());
    }

    public function testALoopbackRegistrationEndpointIsRefusedUnlessOptedIn(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the registration endpoint "http://127.0.0.1:9000/register" is not an absolute HTTPS URL.');

        $this->resolve(new RecordingHttpClient(), $this->metadata(registrationEndpoint: 'http://127.0.0.1:9000/register'), $this->options());
    }

    public function testAServerOfferingNoMechanismIsRefused(): void
    {
        $this->expectException(ClientRegistrationRequiredException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" supports neither Client ID Metadata Documents nor Dynamic Client Registration, so a client identifier must be supplied for it.');

        $this->resolve(new RecordingHttpClient(), $this->metadata(), $this->options());
    }

    public function testTheTimeoutBoundsBothTheTransferAndTheStall(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['client_id' => 'registered']);

        (new ClientRegistrar($http, new InMemoryClientRegistrationStore(), 2.5))->resolve(
            $this->metadata(registrationEndpoint: 'https://auth.example.com/register'),
            $this->options(),
            new NullCancellation(),
        );

        self::assertSame(2.5, $http->readRequest()->getTransferTimeout());
        self::assertSame(2.5, $http->readRequest()->getInactivityTimeout());
    }

    public function testForgettingARegistrationSendsTheNextResolutionBackToTheRegistrationEndpoint(): void
    {
        $store = new InMemoryClientRegistrationStore();
        $http = (new RecordingHttpClient())
            ->willAnswerJson(['client_id' => 'first'])
            ->willAnswerJson(['client_id' => 'second'])
        ;
        $registrar = new ClientRegistrar($http, $store);
        $metadata = $this->metadata(registrationEndpoint: 'https://auth.example.com/register');
        $registrar->resolve($metadata, $this->options(), new NullCancellation());

        $registrar->forget(self::ISSUER);

        self::assertSame(
            'second',
            $registrar->resolve($metadata, $this->options(), new NullCancellation())->clientId,
        );
    }

    private function resolve(
        RecordingHttpClient $http,
        AuthorizationServerMetadata $metadata,
        AuthorizationOptions $options,
        ?InMemoryClientRegistrationStore $store = null,
    ): ClientRegistration {
        return (new ClientRegistrar($http, $store ?? new InMemoryClientRegistrationStore()))->resolve($metadata, $options, new NullCancellation());
    }

    /**
     * @param null|non-empty-string $registrationEndpoint
     */
    private function metadata(?bool $cimdSupported = null, ?string $registrationEndpoint = null): AuthorizationServerMetadata
    {
        return new AuthorizationServerMetadata(
            self::ISSUER,
            registrationEndpoint: $registrationEndpoint,
            clientIdMetadataDocumentSupported: $cimdSupported,
        );
    }

    private function options(
        ?string $clientIdMetadataDocumentUrl = null,
        ?ClientRegistration $preRegistered = null,
        ApplicationType $applicationType = ApplicationType::Native,
    ): AuthorizationOptions {
        return new AuthorizationOptions(
            'Example MCP Client',
            'http://localhost:3000/callback',
            $clientIdMetadataDocumentUrl,
            $preRegistered,
            $applicationType,
        );
    }
}
