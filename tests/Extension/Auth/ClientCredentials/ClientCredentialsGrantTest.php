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

namespace Nexus\Mcp\Tests\Extension\Auth\ClientCredentials;

use Amp\Http\Client\Request;
use Amp\NullCancellation;
use Firebase\JWT\JWT;
use Nexus\Mcp\Client\Auth\AuthorizationOptions;
use Nexus\Mcp\Client\Auth\ClientRegistrar;
use Nexus\Mcp\Client\Auth\ClientRegistration;
use Nexus\Mcp\Client\Auth\DiscoveredResource;
use Nexus\Mcp\Client\Auth\GrantContext;
use Nexus\Mcp\Client\Auth\InMemoryClientRegistrationStore;
use Nexus\Mcp\Client\Auth\TokenEndpoint;
use Nexus\Mcp\Core\Auth\AuthorizationServerMetadata;
use Nexus\Mcp\Core\Auth\ProtectedResourceMetadata;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\ScopeSet;
use Nexus\Mcp\Extension\Auth\ClientCredentials\ClientCredentialsGrant;
use Nexus\Mcp\Extension\Auth\ClientCredentials\ClientSecretCredential;
use Nexus\Mcp\Extension\Auth\ClientCredentials\PrivateKeyJwtCredential;
use Nexus\Mcp\Extension\Auth\Exception\UnsupportedClientAuthenticationException;
use Nexus\Mcp\Extension\Auth\Exception\UnsupportedGrantException;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

/**
 * @internal
 */
#[CoversClass(ClientCredentialsGrant::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ClientCredentialsGrantTest extends TestCase
{
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string ISSUER = 'https://auth.example.com';

    public function testGrantPresentsTheBasicCredentials(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::tokenResponse());
        $grant = new ClientCredentialsGrant(new ClientSecretCredential('the-client', 'the-secret'));

        $token = $grant->grant(self::context($http, self::metadata()), new NullCancellation());

        self::assertSame('the-access-token', $token->value);
        self::assertSame(self::ISSUER, $token->issuer);

        $request = $http->readRequest();
        self::assertSame('https://auth.example.com/token', (string) $request->getUri());
        self::assertSame('Basic '.base64_encode('the-client:the-secret'), $request->getHeader('Authorization'));
        self::assertSame([
            'grant_type' => 'client_credentials',
            'resource' => self::RESOURCE,
        ], self::readForm($request));
    }

    public function testGrantAsksForTheSelectedScopes(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::tokenResponse());
        $grant = new ClientCredentialsGrant(new ClientSecretCredential('the-client', 'the-secret'));

        $token = $grant->grant(self::context($http, self::metadata(), new ScopeSet(['files:read', 'files:write'])), new NullCancellation());

        self::assertSame(['files:read', 'files:write'], $token->scopes);
        self::assertSame([
            'grant_type' => 'client_credentials',
            'resource' => self::RESOURCE,
            'scope' => 'files:read files:write',
        ], self::readForm($http->readRequest()));
    }

    public function testGrantSignsAndPresentsAClientAssertion(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::tokenResponse());
        $grant = new ClientCredentialsGrant(new PrivateKeyJwtCredential('the-client', self::generatePrivateKey(), 'ES256'));

        $token = $grant->grant(
            self::context($http, self::metadata(methods: ['private_key_jwt'], algorithms: ['ES256'])),
            new NullCancellation(),
        );

        self::assertSame('the-access-token', $token->value);

        $request = $http->readRequest();
        self::assertNull($request->getHeader('Authorization'));

        $form = self::readForm($request);
        self::assertSame(
            ['grant_type', 'resource', 'client_assertion_type', 'client_assertion'],
            array_keys($form),
        );
        self::assertSame('client_credentials', $form['grant_type'] ?? null);
        self::assertSame(self::RESOURCE, $form['resource'] ?? null);
        self::assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $form['client_assertion_type'] ?? null);
        self::assertSame(self::ISSUER, self::readClaims($form['client_assertion'] ?? '')['aud'] ?? null);
    }

    public function testAnAbsentMethodListIsRefused(): void
    {
        $grant = new ClientCredentialsGrant(new ClientSecretCredential('the-client', 'the-secret'));

        $this->expectException(UnsupportedClientAuthenticationException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not advertise the "client_secret_basic" token endpoint authentication method.');

        $grant->grant(self::context(new RecordingHttpClient(), self::metadata(methods: null)), new NullCancellation());
    }

    public function testAMethodListWithoutTheConfiguredMethodIsRefused(): void
    {
        $grant = new ClientCredentialsGrant(new PrivateKeyJwtCredential('the-client', self::generatePrivateKey(), 'ES256'));

        $this->expectException(UnsupportedClientAuthenticationException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not advertise the "private_key_jwt" token endpoint authentication method.');

        $grant->grant(self::context(new RecordingHttpClient(), self::metadata(methods: ['client_secret_basic'])), new NullCancellation());
    }

    public function testAnAlgorithmListWithoutTheConfiguredAlgorithmIsRefused(): void
    {
        $grant = new ClientCredentialsGrant(new PrivateKeyJwtCredential('the-client', self::generatePrivateKey(), 'ES256'));

        $this->expectException(UnsupportedClientAuthenticationException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not advertise the "ES256" client assertion signing algorithm.');

        $grant->grant(
            self::context(new RecordingHttpClient(), self::metadata(methods: ['private_key_jwt'], algorithms: ['RS256'])),
            new NullCancellation(),
        );
    }

    public function testAGrantTypeListWithoutClientCredentialsIsRefused(): void
    {
        $grant = new ClientCredentialsGrant(new ClientSecretCredential('the-client', 'the-secret'));

        $this->expectException(UnsupportedGrantException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not advertise the "client_credentials" grant type.');

        $grant->grant(
            self::context(new RecordingHttpClient(), self::metadata(grantTypes: ['authorization_code'])),
            new NullCancellation(),
        );
    }

    public function testAGrantTypeListNamingClientCredentialsProceeds(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::tokenResponse());
        $grant = new ClientCredentialsGrant(new ClientSecretCredential('the-client', 'the-secret'));

        $token = $grant->grant(
            self::context($http, self::metadata(grantTypes: ['authorization_code', 'client_credentials'])),
            new NullCancellation(),
        );

        self::assertSame('the-access-token', $token->value);
    }

    public function testAPreRegisteredCredentialAlongsideTheGrantIsRefused(): void
    {
        $http = new RecordingHttpClient();
        $grant = new ClientCredentialsGrant(new ClientSecretCredential('the-client', 'the-secret'));
        $options = new AuthorizationOptions(
            'Example MCP Client',
            preRegistered: new ClientRegistration('another-client', null, 'another-secret'),
        );

        try {
            $grant->grant(self::context($http, self::metadata(), options: $options), new NullCancellation());
            self::fail('The grant should have been refused.');
        } catch (UnsupportedClientAuthenticationException $e) {
            self::assertSame(
                'The client credentials grant authenticates with the credential it was given, so the authorization options must not carry a pre-registered one as well.',
                $e->getMessage(),
            );
            self::assertSame([], $http->requests);
        }
    }

    public function testItRenewsByAFreshGrant(): void
    {
        self::assertTrue(new ClientCredentialsGrant(new ClientSecretCredential('the-client', 'the-secret'))->renewsByFreshGrant());
    }

    /**
     * @param null|list<non-empty-string> $methods
     * @param null|list<non-empty-string> $algorithms
     * @param null|list<non-empty-string> $grantTypes
     */
    private static function metadata(
        ?array $methods = ['client_secret_basic', 'private_key_jwt'],
        ?array $algorithms = null,
        ?array $grantTypes = null,
    ): AuthorizationServerMetadata {
        return new AuthorizationServerMetadata(
            self::ISSUER,
            tokenEndpoint: 'https://auth.example.com/token',
            tokenEndpointAuthMethodsSupported: $methods,
            tokenEndpointAuthSigningAlgValuesSupported: $algorithms,
            grantTypesSupported: $grantTypes,
        );
    }

    private static function context(
        RecordingHttpClient $http,
        AuthorizationServerMetadata $server,
        ScopeSet $scopes = new ScopeSet(),
        ?AuthorizationOptions $options = null,
    ): GrantContext {
        $resource = new ResourceIdentifier(self::RESOURCE);

        return new GrantContext(
            new DiscoveredResource(new ProtectedResourceMetadata($resource, [self::ISSUER]), $server),
            $resource,
            $scopes,
            $options ?? new AuthorizationOptions('Example MCP Client'),
            $http,
            new ArrayLogger(),
            new ClientRegistrar($http, new InMemoryClientRegistrationStore()),
            new TokenEndpoint($http),
        );
    }

    /**
     * @return non-empty-string
     */
    private static function generatePrivateKey(): string
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);

        if (false === $key || ! openssl_pkey_export($key, $privatePem) || ! \is_string($privatePem) || '' === $privatePem) {
            self::fail('The EC key could not be generated.');
        }

        return $privatePem;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function readClaims(string $assertion): array
    {
        $parts = explode('.', $assertion, 3);

        if (! isset($parts[1])) {
            self::fail('The assertion is not a JWS.');
        }

        $decoded = json_decode(JWT::urlsafeB64Decode($parts[1]), associative: true, flags: \JSON_THROW_ON_ERROR);

        if (! \is_array($decoded)) {
            self::fail('The assertion payload is not a JSON object.');
        }

        return $decoded;
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
