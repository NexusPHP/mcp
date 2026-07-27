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
use Nexus\Mcp\Client\Auth\JsonHttpExchange;
use Nexus\Mcp\Client\Auth\MetadataDiscovery;
use Nexus\Mcp\Client\Exception\AuthorizationDiscoveryFailedException;
use Nexus\Mcp\Client\Exception\MalformedAuthorizationResponseException;
use Nexus\Mcp\Client\Exception\PkceNotSupportedException;
use Nexus\Mcp\Client\Exception\UntrustedAuthorizationMetadataException;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\WwwAuthenticateChallenge;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MetadataDiscovery::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class MetadataDiscoveryTest extends TestCase
{
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string ISSUER = 'https://auth.example.com';

    public function testTheChallengeUrlIsProbedFirst(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::resourceDocument());

        $metadata = new MetadataDiscovery($http)->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            new WwwAuthenticateChallenge('Bearer', ['resource_metadata' => 'https://mcp.example.com/custom/prm']),
            new NullCancellation(),
        );

        self::assertSame('https://mcp.example.com/custom/prm', (string) $http->readRequest()->getUri());
        self::assertSame('GET', $http->readRequest()->getMethod());
        self::assertSame('application/json', $http->readRequest()->getHeader('Accept'));
        self::assertSame([self::ISSUER], $metadata->authorizationServers);
    }

    public function testThePathScopedWellKnownUrlIsProbedBeforeTheRoot(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::resourceDocument());

        new MetadataDiscovery($http)->discoverResource(new ResourceIdentifier(self::RESOURCE), null, new NullCancellation());

        self::assertSame(
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
            (string) $http->readRequest()->getUri(),
        );
    }

    public function testTheRootWellKnownUrlIsProbedWhenThePathScopedOneIsAbsent(): void
    {
        $http = new RecordingHttpClient()
            ->willAnswerJson([], 404)
            ->willAnswerJson(self::resourceDocument())
        ;

        new MetadataDiscovery($http)->discoverResource(new ResourceIdentifier(self::RESOURCE), null, new NullCancellation());

        self::assertCount(2, $http->requests);
        self::assertSame(
            'https://mcp.example.com/.well-known/oauth-protected-resource',
            (string) $http->readRequest(1)->getUri(),
        );
    }

    public function testTheWellKnownUrlIsProbedWhenTheChallengeAdvertisesNone(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::resourceDocument());

        new MetadataDiscovery($http)->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            new WwwAuthenticateChallenge('Bearer', ['scope' => 'files:read']),
            new NullCancellation(),
        );

        self::assertSame(
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
            (string) $http->readRequest()->getUri(),
        );
    }

    public function testTheResourceDocumentIsRead(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::resourceDocument(['scopes_supported' => ['files:read']]));

        $metadata = new MetadataDiscovery($http)->discoverResource(new ResourceIdentifier(self::RESOURCE), null, new NullCancellation());

        self::assertSame(self::RESOURCE, $metadata->resource->value);
        self::assertSame([self::ISSUER], $metadata->authorizationServers);
        self::assertSame(['files:read'], $metadata->scopesSupported?->values);
    }

    public function testAResourceDocumentNamingAnotherResourceIsRefused(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::resourceDocument(['resource' => 'https://attacker.example/mcp']));

        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the document served for "https://mcp.example.com/mcp" names the resource "https://attacker.example/mcp".');

        new MetadataDiscovery($http)->discoverResource(new ResourceIdentifier(self::RESOURCE), null, new NullCancellation());
    }

    public function testExhaustingEveryResourceCandidateIsReported(): void
    {
        $http = new RecordingHttpClient()
            ->willAnswerJson([], 404)
            ->willAnswerJson([], 404)
        ;

        $this->expectException(AuthorizationDiscoveryFailedException::class);
        $this->expectExceptionMessageIs('No protected resource metadata was served for "https://mcp.example.com/mcp". Probed: https://mcp.example.com/.well-known/oauth-protected-resource/mcp, https://mcp.example.com/.well-known/oauth-protected-resource.');

        new MetadataDiscovery($http)->discoverResource(new ResourceIdentifier(self::RESOURCE), null, new NullCancellation());
    }

    #[DataProvider('provideAnAdvertisedUrlOffTheMcpServersOriginIsRefusedCases')]
    public function testAnAdvertisedUrlOffTheMcpServersOriginIsRefused(string $advertised): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The authorization metadata cannot be trusted because the advertised protected resource metadata URL "%s" is not served by the MCP server it describes.',
            $advertised,
        ));

        new MetadataDiscovery(new RecordingHttpClient())->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            new WwwAuthenticateChallenge('Bearer', ['resource_metadata' => $advertised]),
            new NullCancellation(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnAdvertisedUrlOffTheMcpServersOriginIsRefusedCases(): iterable
    {
        yield 'cleartext downgrades the origin' => ['http://mcp.example.com/prm'];

        yield 'another host is another origin' => ['https://attacker.example.com/prm'];

        yield 'another port is another origin' => ['https://mcp.example.com:8443/prm'];

        yield 'a loopback address reaches past the public internet' => ['http://127.0.0.1:6379/prm'];
    }

    public function testAnAdvertisedUrlSpellingTheDefaultPortSharesTheOrigin(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::resourceDocument());

        new MetadataDiscovery($http)->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            new WwwAuthenticateChallenge('Bearer', ['resource_metadata' => 'https://mcp.example.com:443/prm']),
            new NullCancellation(),
        );

        self::assertSame('https://mcp.example.com/prm', (string) $http->readRequest()->getUri());
    }

    public function testAResourceDocumentThatIsNotAJsonObjectIsRefused(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson('"not-an-object"');

        $this->expectException(MalformedAuthorizationResponseException::class);
        $this->expectExceptionMessageIs('The protected resource metadata URL answered with a payload that is not a JSON object.');

        new MetadataDiscovery($http)->discoverResource(new ResourceIdentifier(self::RESOURCE), null, new NullCancellation());
    }

    public function testTheAuthorizationServerDocumentIsRead(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::serverDocument());

        $metadata = new MetadataDiscovery($http)->discoverServer(self::ISSUER, new ResourceIdentifier(self::RESOURCE), new NullCancellation());

        self::assertSame(self::ISSUER, $metadata->issuer);
        self::assertSame('https://auth.example.com/token', $metadata->tokenEndpoint);
        self::assertSame(
            'https://auth.example.com/.well-known/oauth-authorization-server',
            (string) $http->readRequest()->getUri(),
        );
    }

    public function testTheOpenIdConfigurationIsProbedWhenRfc8414IsAbsent(): void
    {
        $http = new RecordingHttpClient()
            ->willAnswerJson([], 404)
            ->willAnswerJson(self::serverDocument())
        ;

        new MetadataDiscovery($http)->discoverServer(self::ISSUER, new ResourceIdentifier(self::RESOURCE), new NullCancellation());

        self::assertCount(2, $http->requests);
        self::assertSame(
            'https://auth.example.com/.well-known/openid-configuration',
            (string) $http->readRequest(1)->getUri(),
        );
    }

    public function testAPathBearingIssuerProbesThreeUrlsInOrder(): void
    {
        $http = new RecordingHttpClient()
            ->willAnswerJson([], 404)
            ->willAnswerJson([], 404)
            ->willAnswerJson(self::serverDocument(['issuer' => 'https://auth.example.com/tenant1']))
        ;

        new MetadataDiscovery($http)->discoverServer('https://auth.example.com/tenant1', new ResourceIdentifier(self::RESOURCE), new NullCancellation());

        self::assertSame([
            'https://auth.example.com/.well-known/oauth-authorization-server/tenant1',
            'https://auth.example.com/.well-known/openid-configuration/tenant1',
            'https://auth.example.com/tenant1/.well-known/openid-configuration',
        ], array_map(static fn($request): string => (string) $request->getUri(), $http->requests));
    }

    public function testAServerDocumentNamingAnotherIssuerIsRefused(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::serverDocument(['issuer' => 'https://honest.example']));

        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the document served for "https://auth.example.com" names the issuer "https://honest.example".');

        new MetadataDiscovery($http)->discoverServer(self::ISSUER, new ResourceIdentifier(self::RESOURCE), new NullCancellation());
    }

    public function testACleartextIssuerIsNeverProbed(): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the authorization server metadata URL "http://auth.example.com/.well-known/oauth-authorization-server" is not served over HTTPS.');

        try {
            new MetadataDiscovery($http)->discoverServer('http://auth.example.com', new ResourceIdentifier(self::RESOURCE), new NullCancellation());
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    public function testALoopbackMcpServerMayNameACleartextIssuer(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::serverDocument(['issuer' => 'http://localhost:9000']));

        $metadata = new MetadataDiscovery($http)->discoverServer(
            'http://localhost:9000',
            new ResourceIdentifier('http://localhost:9000/mcp'),
            new NullCancellation(),
        );

        self::assertSame('http://localhost:9000', $metadata->issuer);
    }

    public function testAServerAdvertisingNoCodeChallengeMethodsIsRefused(): void
    {
        $document = self::serverDocument();
        unset($document['code_challenge_methods_supported']);
        $http = new RecordingHttpClient()->willAnswerJson($document);

        $this->expectException(PkceNotSupportedException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not advertise the S256 code challenge method, so authorization cannot proceed.');

        new MetadataDiscovery($http)->discoverServer(self::ISSUER, new ResourceIdentifier(self::RESOURCE), new NullCancellation());
    }

    public function testAServerAdvertisingOnlyPlainIsRefused(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::serverDocument(['code_challenge_methods_supported' => ['plain']]));

        $this->expectException(PkceNotSupportedException::class);
        $this->expectExceptionMessageIs('The authorization server "https://auth.example.com" does not advertise the S256 code challenge method, so authorization cannot proceed.');

        new MetadataDiscovery($http)->discoverServer(self::ISSUER, new ResourceIdentifier(self::RESOURCE), new NullCancellation());
    }

    public function testExhaustingEveryServerCandidateIsReported(): void
    {
        $http = new RecordingHttpClient()
            ->willAnswerJson([], 404)
            ->willAnswerJson([], 404)
        ;

        $this->expectException(AuthorizationDiscoveryFailedException::class);
        $this->expectExceptionMessageIs('No authorization server metadata was served for "https://auth.example.com". Probed: https://auth.example.com/.well-known/oauth-authorization-server, https://auth.example.com/.well-known/openid-configuration.');

        new MetadataDiscovery($http)->discoverServer(self::ISSUER, new ResourceIdentifier(self::RESOURCE), new NullCancellation());
    }

    public function testAMissIsDrainedSoItsConnectionIsReleased(): void
    {
        $http = new RecordingHttpClient()
            ->willAnswerJson([], 404)
            ->willAnswerJson(self::resourceDocument())
        ;

        new MetadataDiscovery($http)->discoverResource(new ResourceIdentifier(self::RESOURCE), null, new NullCancellation());

        self::assertTrue($http->drainedBodies[0] ?? false);
    }

    public function testAnOversizedMissStillFallsThroughToTheNextCandidate(): void
    {
        $http = new RecordingHttpClient()
            ->willAnswer404WithBody(str_repeat('x', JsonHttpExchange::MAX_RESPONSE_BYTES + 1))
            ->willAnswerJson(self::resourceDocument())
        ;

        $metadata = new MetadataDiscovery($http)->discoverResource(new ResourceIdentifier(self::RESOURCE), null, new NullCancellation());

        self::assertSame(self::RESOURCE, $metadata->resource->value);
    }

    public function testAnOversizedDocumentFallsThroughToTheNextCandidate(): void
    {
        $http = new RecordingHttpClient()
            ->willAnswerJson(str_repeat('x', JsonHttpExchange::MAX_RESPONSE_BYTES + 1))
            ->willAnswerJson(self::resourceDocument())
        ;

        $metadata = new MetadataDiscovery($http)->discoverResource(new ResourceIdentifier(self::RESOURCE), null, new NullCancellation());

        self::assertSame(self::RESOURCE, $metadata->resource->value);
    }

    public function testTheTimeoutBoundsBothTheTransferAndTheStall(): void
    {
        $http = new RecordingHttpClient()->willAnswerJson(self::serverDocument());

        new MetadataDiscovery($http, 2.5)->discoverServer(self::ISSUER, new ResourceIdentifier(self::RESOURCE), new NullCancellation());

        self::assertSame(2.5, $http->readRequest()->getTransferTimeout());
        self::assertSame(2.5, $http->readRequest()->getInactivityTimeout());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function resourceDocument(array $overrides = []): array
    {
        return ['resource' => self::RESOURCE, 'authorization_servers' => [self::ISSUER], ...$overrides];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function serverDocument(array $overrides = []): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => 'https://auth.example.com/authorize',
            'token_endpoint' => 'https://auth.example.com/token',
            'code_challenge_methods_supported' => ['S256'],
            ...$overrides,
        ];
    }
}
