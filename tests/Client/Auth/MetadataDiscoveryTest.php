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
use Nexus\Mcp\Client\Exception\UntrustedAuthorizationMetadataException;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use Nexus\Mcp\Core\Auth\WwwAuthenticateChallenge;
use Nexus\Mcp\Core\Exception\RuntimeException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(MetadataDiscovery::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class MetadataDiscoveryTest extends AbstractMcpTestCase
{
    private const string RESOURCE = 'https://mcp.example.com/mcp';
    private const string ISSUER = 'https://auth.example.com';

    public function testTheChallengeUrlIsProbedFirst(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::buildResourceDocument());

        $metadata = (new MetadataDiscovery($http))->discoverResource(
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
        $http = (new RecordingHttpClient())->willAnswerJson(self::buildResourceDocument());

        (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );

        self::assertSame(
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
            (string) $http->readRequest()->getUri(),
        );
    }

    public function testTheRootWellKnownUrlIsProbedWhenThePathScopedOneIsAbsent(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson([], 404)
            ->willAnswerJson(self::buildResourceDocument())
        ;

        (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );

        self::assertCount(2, $http->requests);
        self::assertSame(
            'https://mcp.example.com/.well-known/oauth-protected-resource',
            (string) $http->readRequest(1)->getUri(),
        );
    }

    public function testTheWellKnownUrlIsProbedWhenTheChallengeAdvertisesNone(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::buildResourceDocument());

        (new MetadataDiscovery($http))->discoverResource(
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
        $document = self::buildResourceDocument(['scopes_supported' => ['files:read']]);
        $http = (new RecordingHttpClient())->willAnswerJson($document);

        $metadata = (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );

        self::assertSame(self::RESOURCE, $metadata->resource->value);
        self::assertSame([self::ISSUER], $metadata->authorizationServers);
        self::assertSame(['files:read'], $metadata->scopesSupported?->values);
    }

    public function testAResourceDocumentNamingAnotherResourceIsRefused(): void
    {
        $document = self::buildResourceDocument(['resource' => 'https://attacker.example/mcp']);
        $http = (new RecordingHttpClient())->willAnswerJson($document);

        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the document served for "https://mcp.example.com/mcp" names the resource "https://attacker.example/mcp".');

        (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );
    }

    public function testTheRootDocumentMayNameTheOriginRatherThanTheEndpoint(): void
    {
        // RFC 9728 assigns the origin-root well-known URL to the resource at the origin, so the document it serves
        // names that origin.
        $http = (new RecordingHttpClient())
            ->willAnswerJson([], 404)
            ->willAnswerJson(self::buildResourceDocument(['resource' => 'https://mcp.example.com']))
        ;

        $metadata = (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );

        self::assertSame('https://mcp.example.com', $metadata->resource->value);
    }

    public function testADocumentNamingAnotherOriginsRootIsStillRefused(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson([], 404)
            ->willAnswerJson(self::buildResourceDocument(['resource' => 'https://attacker.example']))
        ;

        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the document served for "https://mcp.example.com/mcp" names the resource "https://attacker.example".');

        (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );
    }

    public function testASiblingPathOnTheSameOriginIsStillRefused(): void
    {
        $document = self::buildResourceDocument(['resource' => 'https://mcp.example.com/other']);
        $http = (new RecordingHttpClient())->willAnswerJson($document);

        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the document served for "https://mcp.example.com/mcp" names the resource "https://mcp.example.com/other".');

        (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );
    }

    public function testExhaustingEveryResourceCandidateIsReported(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson([], 404)
            ->willAnswerJson([], 404)
        ;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('No protected resource metadata was served for "https://mcp.example.com/mcp". Probed: https://mcp.example.com/.well-known/oauth-protected-resource/mcp, https://mcp.example.com/.well-known/oauth-protected-resource.');

        (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );
    }

    public function testAnOverlongProbedUrlIsBoundedInTheReport(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson([], 404)
            ->willAnswerJson([], 404)
        ;
        $resource = 'https://mcp.example.com/'.str_repeat('a', 400);

        try {
            (new MetadataDiscovery($http))->discoverResource(
                new ResourceIdentifier($resource),
                null,
                new NullCancellation(),
            );
            self::fail('Expected discovery to fail.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('...', $e->getMessage());
            self::assertDoesNotMatchRegularExpression(
                \sprintf('/[a]{%d}/', 256 + 1),
                $e->getMessage(),
            );
        }
    }

    #[DataProvider('provideAnAdvertisedUrlOffTheMcpServersOriginIsDroppedCases')]
    public function testAnAdvertisedUrlOffTheMcpServersOriginIsDropped(string $advertised): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::buildResourceDocument());

        $metadata = (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            new WwwAuthenticateChallenge('Bearer', ['resource_metadata' => $advertised]),
            new NullCancellation(),
        );

        self::assertSame(self::RESOURCE, $metadata->resource->value);
        self::assertSame(
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
            (string) $http->readRequest()->getUri(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnAdvertisedUrlOffTheMcpServersOriginIsDroppedCases(): iterable
    {
        yield 'cleartext downgrades the origin' => ['http://mcp.example.com/prm'];

        yield 'another host is another origin' => ['https://attacker.example.com/prm'];

        yield 'another port is another origin' => ['https://mcp.example.com:8443/prm'];

        yield 'a loopback address reaches past the public internet' => ['http://127.0.0.1:6379/prm'];

        yield 'a URL that is not absolute names no origin' => ['/prm'];
    }

    public function testTheWellKnownUrlsAreStillProbedWhenTheAdvertisedOneMisses(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson([], 404)
            ->willAnswerJson(self::buildResourceDocument())
        ;

        (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            new WwwAuthenticateChallenge('Bearer', ['resource_metadata' => 'https://mcp.example.com/custom/prm']),
            new NullCancellation(),
        );

        self::assertSame([
            'https://mcp.example.com/custom/prm',
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
        ], array_map(static fn($request): string => (string) $request->getUri(), $http->requests));
    }

    public function testAnAdvertisedUrlSpellingTheDefaultPortSharesTheOrigin(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::buildResourceDocument());

        (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            new WwwAuthenticateChallenge('Bearer', ['resource_metadata' => 'https://mcp.example.com:443/prm']),
            new NullCancellation(),
        );

        self::assertSame('https://mcp.example.com/prm', (string) $http->readRequest()->getUri());
    }

    #[DataProvider('provideACandidateThatAnswersNothingUsableLeavesTheNextOneItsTurnCases')]
    public function testACandidateThatAnswersNothingUsableLeavesTheNextOneItsTurn(RecordingHttpClient $http): void
    {
        $metadata = (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );

        self::assertSame(self::RESOURCE, $metadata->resource->value);
        self::assertSame(
            'https://mcp.example.com/.well-known/oauth-protected-resource',
            (string) $http->readRequest(1)->getUri(),
        );
    }

    /**
     * @return iterable<string, array{RecordingHttpClient}>
     */
    public static function provideACandidateThatAnswersNothingUsableLeavesTheNextOneItsTurnCases(): iterable
    {
        yield 'a payload that is not a JSON object' => [
            (new RecordingHttpClient())
                ->willAnswerJson('"not-an-object"')
                ->willAnswerJson(self::buildResourceDocument()),
        ];

        yield 'a payload that is not JSON at all' => [
            (new RecordingHttpClient())
                ->willAnswerJson('<html>Bad Gateway</html>')
                ->willAnswerJson(self::buildResourceDocument()),
        ];

        yield 'an answer that arrived from somewhere else' => [
            (new RecordingHttpClient())
                ->willAnswerFrom('https://mcp.example.com/moved', self::buildResourceDocument())
                ->willAnswerJson(self::buildResourceDocument()),
        ];

        yield 'a body too large to read' => [
            (new RecordingHttpClient())
                ->willAnswerJson(str_repeat('x', 65_537))
                ->willAnswerJson(self::buildResourceDocument()),
        ];
    }

    public function testEveryCandidateAnsweringNothingUsableIsReportedAsAMiss(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson('"not-an-object"')
            ->willAnswerJson('"not-an-object"')
        ;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('No protected resource metadata was served for "https://mcp.example.com/mcp". Probed: https://mcp.example.com/.well-known/oauth-protected-resource/mcp, https://mcp.example.com/.well-known/oauth-protected-resource.');

        (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );
    }

    public function testAnAdvertisedUrlThatCannotBeBuiltIntoARequestLeavesTheWellKnownOnesTheirTurn(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::buildResourceDocument());

        $metadata = (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            new WwwAuthenticateChallenge('Bearer', ['resource_metadata' => 'https://mcp.example.com/prm ']),
            new NullCancellation(),
        );

        self::assertSame(self::RESOURCE, $metadata->resource->value);
        self::assertSame(
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
            (string) $http->readRequest()->getUri(),
        );
    }

    public function testAResourceDocumentOffTheShapeTheSpecFixesIsRefused(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['resource' => 'not a resource identifier']);

        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the protected resource metadata URL answered with a document off the shape the spec fixes.');

        (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );
    }

    public function testAServerDocumentOffTheShapeTheSpecFixesIsRefused(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['issuer' => ['not', 'a', 'string']]);

        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the authorization server metadata URL answered with a document off the shape the spec fixes.');

        (new MetadataDiscovery($http))->discoverServer(self::ISSUER, new NullCancellation());
    }

    public function testTheAuthorizationServerDocumentIsRead(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson($this->buildServerDocument());

        $metadata = (new MetadataDiscovery($http))->discoverServer(self::ISSUER, new NullCancellation());

        self::assertSame(self::ISSUER, $metadata->issuer);
        self::assertSame('https://auth.example.com/token', $metadata->tokenEndpoint);
        self::assertSame(
            'https://auth.example.com/.well-known/oauth-authorization-server',
            (string) $http->readRequest()->getUri(),
        );
    }

    public function testTheOpenIdConfigurationIsProbedWhenRfc8414IsAbsent(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson([], 404)
            ->willAnswerJson($this->buildServerDocument())
        ;

        (new MetadataDiscovery($http))->discoverServer(self::ISSUER, new NullCancellation());

        self::assertCount(2, $http->requests);
        self::assertSame(
            'https://auth.example.com/.well-known/openid-configuration',
            (string) $http->readRequest(1)->getUri(),
        );
    }

    public function testAPathBearingIssuerProbesThreeUrlsInOrder(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson([], 404)
            ->willAnswerJson([], 404)
            ->willAnswerJson($this->buildServerDocument(['issuer' => 'https://auth.example.com/tenant1']))
        ;

        (new MetadataDiscovery($http))->discoverServer('https://auth.example.com/tenant1', new NullCancellation());

        self::assertSame([
            'https://auth.example.com/.well-known/oauth-authorization-server/tenant1',
            'https://auth.example.com/.well-known/openid-configuration/tenant1',
            'https://auth.example.com/tenant1/.well-known/openid-configuration',
        ], array_map(static fn($request): string => (string) $request->getUri(), $http->requests));
    }

    public function testAServerDocumentNamingAnotherIssuerIsRefused(): void
    {
        $document = $this->buildServerDocument(['issuer' => 'https://honest.example']);
        $http = (new RecordingHttpClient())->willAnswerJson($document);

        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the document served for "https://auth.example.com" names the issuer "https://honest.example".');

        (new MetadataDiscovery($http))->discoverServer(self::ISSUER, new NullCancellation());
    }

    #[DataProvider('provideAnIssuerThatIsNotHttpsIsNeverProbedCases')]
    public function testAnIssuerThatIsNotHttpsIsNeverProbed(string $issuer): void
    {
        $http = new RecordingHttpClient();

        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The authorization metadata cannot be trusted because the authorization server issuer "%s" is not an absolute HTTPS URL.',
            $issuer,
        ));

        try {
            (new MetadataDiscovery($http))->discoverServer($issuer, new NullCancellation());
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnIssuerThatIsNotHttpsIsNeverProbedCases(): iterable
    {
        yield 'a remote cleartext issuer' => ['http://auth.example.com'];

        yield 'a loopback issuer' => ['http://localhost:9000'];

        yield 'an issuer that is not an absolute URL' => ['auth.example.com'];
    }

    public function testAServerAdvertisingNoCodeChallengeMethodsIsStillDiscovered(): void
    {
        $document = $this->buildServerDocument();
        unset($document['code_challenge_methods_supported']);
        $http = (new RecordingHttpClient())->willAnswerJson($document);

        $server = (new MetadataDiscovery($http))->discoverServer(self::ISSUER, new NullCancellation());

        self::assertSame(self::ISSUER, $server->issuer);
        self::assertNull($server->codeChallengeMethodsSupported);
    }

    public function testExhaustingEveryServerCandidateIsReported(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson([], 404)
            ->willAnswerJson([], 404)
        ;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('No authorization server metadata was served for "https://auth.example.com". Probed: https://auth.example.com/.well-known/oauth-authorization-server, https://auth.example.com/.well-known/openid-configuration.');

        (new MetadataDiscovery($http))->discoverServer(self::ISSUER, new NullCancellation());
    }

    public function testAMissIsDrainedSoItsConnectionIsReleased(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson([], 404)
            ->willAnswerJson(self::buildResourceDocument())
        ;

        (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );

        self::assertTrue($http->drainedBodies[0] ?? false);
    }

    public function testAnOversizedMissStillFallsThroughToTheNextCandidate(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswer404WithBody(str_repeat('x', JsonHttpExchange::MAX_RESPONSE_BYTES + 1))
            ->willAnswerJson(self::buildResourceDocument())
        ;

        $metadata = (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );

        self::assertSame(self::RESOURCE, $metadata->resource->value);
    }

    public function testAnOversizedDocumentFallsThroughToTheNextCandidate(): void
    {
        $http = (new RecordingHttpClient())
            ->willAnswerJson(str_repeat('x', JsonHttpExchange::MAX_RESPONSE_BYTES + 1))
            ->willAnswerJson(self::buildResourceDocument())
        ;

        $metadata = (new MetadataDiscovery($http))->discoverResource(
            new ResourceIdentifier(self::RESOURCE),
            null,
            new NullCancellation(),
        );

        self::assertSame(self::RESOURCE, $metadata->resource->value);
    }

    public function testTheTimeoutBoundsBothTheTransferAndTheStall(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson($this->buildServerDocument());

        (new MetadataDiscovery($http, 2.5))->discoverServer(self::ISSUER, new NullCancellation());

        self::assertSame(2.5, $http->readRequest()->getTransferTimeout());
        self::assertSame(2.5, $http->readRequest()->getInactivityTimeout());
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideBuildProtectedResourceUrlsCases')]
    public function testBuildProtectedResourceUrls(string $resource, array $expected): void
    {
        self::assertSame($expected, MetadataDiscovery::buildProtectedResourceUrls($resource));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideBuildProtectedResourceUrlsCases(): iterable
    {
        yield 'a path-scoped endpoint probes the path form first' => [
            'https://example.com/public/mcp',
            [
                'https://example.com/.well-known/oauth-protected-resource/public/mcp',
                'https://example.com/.well-known/oauth-protected-resource',
            ],
        ];

        yield 'a root endpoint has only the root form' => [
            'https://example.com',
            ['https://example.com/.well-known/oauth-protected-resource'],
        ];

        yield 'a bare root path has only the root form' => [
            'https://example.com/',
            ['https://example.com/.well-known/oauth-protected-resource'],
        ];

        yield 'a trailing slash does not reach the well-known path' => [
            'https://example.com/mcp/',
            [
                'https://example.com/.well-known/oauth-protected-resource/mcp',
                'https://example.com/.well-known/oauth-protected-resource',
            ],
        ];

        yield 'the origin is lowercased and keeps its port' => [
            'HTTPS://Example.COM:8443/mcp',
            [
                'https://example.com:8443/.well-known/oauth-protected-resource/mcp',
                'https://example.com:8443/.well-known/oauth-protected-resource',
            ],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideBuildAuthorizationServerUrlsCases')]
    public function testBuildAuthorizationServerUrls(string $issuer, array $expected): void
    {
        self::assertSame($expected, MetadataDiscovery::buildAuthorizationServerUrls($issuer));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideBuildAuthorizationServerUrlsCases(): iterable
    {
        yield 'an issuer with a path probes three endpoints in order' => [
            'https://auth.example.com/tenant1',
            [
                'https://auth.example.com/.well-known/oauth-authorization-server/tenant1',
                'https://auth.example.com/.well-known/openid-configuration/tenant1',
                'https://auth.example.com/tenant1/.well-known/openid-configuration',
            ],
        ];

        yield 'an issuer without a path probes two endpoints in order' => [
            'https://auth.example.com',
            [
                'https://auth.example.com/.well-known/oauth-authorization-server',
                'https://auth.example.com/.well-known/openid-configuration',
            ],
        ];

        yield 'a bare root path is not a path component' => [
            'https://auth.example.com/',
            [
                'https://auth.example.com/.well-known/oauth-authorization-server',
                'https://auth.example.com/.well-known/openid-configuration',
            ],
        ];

        yield 'a trailing slash does not reach the well-known path' => [
            'https://auth.example.com/tenant1/',
            [
                'https://auth.example.com/.well-known/oauth-authorization-server/tenant1',
                'https://auth.example.com/.well-known/openid-configuration/tenant1',
                'https://auth.example.com/tenant1/.well-known/openid-configuration',
            ],
        ];

        yield 'the origin is lowercased and keeps its port' => [
            'HTTPS://Auth.Example.COM:8443/tenant1',
            [
                'https://auth.example.com:8443/.well-known/oauth-authorization-server/tenant1',
                'https://auth.example.com:8443/.well-known/openid-configuration/tenant1',
                'https://auth.example.com:8443/tenant1/.well-known/openid-configuration',
            ],
        ];
    }

    public function testAResourceQueryIsKeptOnThePathScopedUrlAndDroppedFromTheRootFallback(): void
    {
        // The root form is the URL assigned by RFC 9728 to another resource, so asking for it under this query would
        // read that one's document.
        self::assertSame([
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp?tenant=acme',
            'https://mcp.example.com/.well-known/oauth-protected-resource',
        ], MetadataDiscovery::buildProtectedResourceUrls('https://mcp.example.com/mcp?tenant=acme'));
    }

    /**
     * @param non-empty-string $uri
     */
    #[DataProvider('provideReadOriginCases')]
    public function testReadOrigin(string $uri, string $expected): void
    {
        self::assertSame($expected, MetadataDiscovery::readOrigin($uri));
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function provideReadOriginCases(): iterable
    {
        yield 'a path is dropped' => ['https://mcp.example.com/mcp', 'https://mcp.example.com'];

        yield 'a query is dropped' => ['https://mcp.example.com/mcp?tenant=acme', 'https://mcp.example.com'];

        yield 'a port is kept' => ['http://localhost:3000/mcp', 'http://localhost:3000'];

        yield 'the scheme and host are lowercased' => ['HTTPS://MCP.Example.COM/mcp', 'https://mcp.example.com'];

        yield 'an origin is already one' => ['https://mcp.example.com', 'https://mcp.example.com'];
    }

    public function testAnIssuerQueryIsDroppedBecauseTheSpecForbidsOne(): void
    {
        self::assertSame([
            'https://auth.example.com/.well-known/oauth-authorization-server',
            'https://auth.example.com/.well-known/openid-configuration',
        ], MetadataDiscovery::buildAuthorizationServerUrls('https://auth.example.com?tenant=acme'));
    }

    public function testARootResourceKeepsItsQueryOnTheWellKnownUrl(): void
    {
        self::assertSame(
            ['https://mcp.example.com/.well-known/oauth-protected-resource?tenant=acme'],
            MetadataDiscovery::buildProtectedResourceUrls('https://mcp.example.com?tenant=acme'),
        );
    }

    #[DataProvider('provideRejectedUrisCases')]
    public function testRejectedUris(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'A well-known metadata URL cannot be built from "%s", which is not an absolute URI.',
            $uri,
        ));

        MetadataDiscovery::buildAuthorizationServerUrls($uri);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectedUrisCases(): iterable
    {
        yield 'an empty string is not a URI' => [''];

        yield 'a bare authority has no scheme' => ['auth.example.com'];

        yield 'a scheme with no host is not an origin' => ['file:///tmp/auth'];
    }

    public function testRejectedUrisAlsoFailForAProtectedResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('A well-known metadata URL cannot be built from "mcp.example.com", which is not an absolute URI.');

        MetadataDiscovery::buildProtectedResourceUrls('mcp.example.com');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function buildResourceDocument(array $overrides = []): array
    {
        return ['resource' => self::RESOURCE, 'authorization_servers' => [self::ISSUER], ...$overrides];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function buildServerDocument(array $overrides = []): array
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
