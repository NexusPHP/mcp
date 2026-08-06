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
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

/**
 * @internal
 */
#[CoversClass(GrantContext::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class GrantContextTest extends TestCase
{
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string ISSUER = 'https://auth.example.com';

    public function testItCarriesWhatDiscoveryFound(): void
    {
        $http = new RecordingHttpClient();
        $resource = new ResourceIdentifier(self::RESOURCE);
        $discovered = self::discovered($resource);
        $scopes = new ScopeSet(['files:read']);
        $options = new AuthorizationOptions('Example MCP Client');
        $logger = new ArrayLogger();

        $context = new GrantContext(
            $discovered,
            $resource,
            $scopes,
            $options,
            $http,
            $logger,
            new ClientRegistrar($http, new InMemoryClientRegistrationStore()),
            new TokenEndpoint($http),
        );

        self::assertSame($discovered, $context->discovered);
        self::assertSame($resource, $context->resource);
        self::assertSame($scopes, $context->scopes);
        self::assertSame($options, $context->options);
        self::assertSame($http, $context->httpClient);
        self::assertSame($logger, $context->logger);
    }

    public function testResolveRegistrationBindsThePreRegisteredCredentialsToTheDiscoveredIssuer(): void
    {
        $context = self::context(new RecordingHttpClient(), new AuthorizationOptions(
            'Example MCP Client',
            preRegistered: new ClientRegistration('the-client', clientSecret: 'the-secret'),
        ));

        $registration = $context->resolveRegistration(new NullCancellation());

        self::assertSame('the-client', $registration->clientId);
        self::assertSame(self::ISSUER, $registration->issuer);
    }

    public function testRequestTokenRedeemsTheGrantAtTheDiscoveredTokenEndpoint(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson([
            'access_token' => 'the-access-token',
            'token_type' => 'Bearer',
        ]);
        $context = self::context($http);

        $token = $context->requestToken(
            new ClientRegistration('the-client', self::ISSUER),
            ['grant_type' => 'client_credentials', 'resource' => self::RESOURCE],
            new NullCancellation(),
        );

        self::assertSame('the-access-token', $token->value);
        self::assertSame(self::ISSUER, $token->issuer);

        $request = $http->readRequest();
        self::assertSame('https://auth.example.com/token', (string) $request->getUri());
        self::assertSame([
            'grant_type' => 'client_credentials',
            'resource' => self::RESOURCE,
            'client_id' => 'the-client',
        ], self::readForm($request));
    }

    public function testRequestTokenFallsBackToTheContextScopesWhenTheResponseNamesNone(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson([
            'access_token' => 'the-access-token',
            'token_type' => 'Bearer',
        ]);
        $context = self::context($http);

        $token = $context->requestToken(
            new ClientRegistration('the-client', self::ISSUER),
            ['grant_type' => 'client_credentials'],
            new NullCancellation(),
        );

        self::assertSame(['files:read'], $token->scopes);
    }

    public function testRequestTokenPrefersTheScopesTheCallerNames(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson([
            'access_token' => 'the-access-token',
            'token_type' => 'Bearer',
        ]);
        $context = self::context($http);

        $token = $context->requestToken(
            new ClientRegistration('the-client', self::ISSUER),
            ['grant_type' => 'authorization_code'],
            new NullCancellation(),
            new ScopeSet(['files:write']),
        );

        self::assertSame(['files:write'], $token->scopes);
    }

    private static function context(RecordingHttpClient $http, ?AuthorizationOptions $options = null): GrantContext
    {
        $resource = new ResourceIdentifier(self::RESOURCE);

        return new GrantContext(
            self::discovered($resource),
            $resource,
            new ScopeSet(['files:read']),
            $options ?? new AuthorizationOptions('Example MCP Client'),
            $http,
            new ArrayLogger(),
            new ClientRegistrar($http, new InMemoryClientRegistrationStore()),
            new TokenEndpoint($http),
        );
    }

    private static function discovered(ResourceIdentifier $resource): DiscoveredResource
    {
        return new DiscoveredResource(
            new ProtectedResourceMetadata($resource, [self::ISSUER]),
            new AuthorizationServerMetadata(self::ISSUER, tokenEndpoint: 'https://auth.example.com/token'),
        );
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
