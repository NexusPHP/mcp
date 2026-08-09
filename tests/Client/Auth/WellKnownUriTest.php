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

use Nexus\Mcp\Client\Auth\WellKnownUri;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(WellKnownUri::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class WellKnownUriTest extends AbstractMcpTestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideForProtectedResourceCases')]
    public function testForProtectedResource(string $resource, array $expected): void
    {
        self::assertSame($expected, WellKnownUri::forProtectedResource($resource));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideForProtectedResourceCases(): iterable
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
    #[DataProvider('provideForAuthorizationServerCases')]
    public function testForAuthorizationServer(string $issuer, array $expected): void
    {
        self::assertSame($expected, WellKnownUri::forAuthorizationServer($issuer));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideForAuthorizationServerCases(): iterable
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
        // The root form is the URL RFC 9728 assigns to another resource, so asking for it under this query would read that one's document.
        self::assertSame([
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp?tenant=acme',
            'https://mcp.example.com/.well-known/oauth-protected-resource',
        ], WellKnownUri::forProtectedResource('https://mcp.example.com/mcp?tenant=acme'));
    }

    /**
     * @param non-empty-string $uri
     */
    #[DataProvider('provideOriginOfCases')]
    public function testOriginOf(string $uri, string $expected): void
    {
        self::assertSame($expected, WellKnownUri::originOf($uri));
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function provideOriginOfCases(): iterable
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
        ], WellKnownUri::forAuthorizationServer('https://auth.example.com?tenant=acme'));
    }

    public function testARootResourceKeepsItsQueryOnTheWellKnownUrl(): void
    {
        self::assertSame(
            ['https://mcp.example.com/.well-known/oauth-protected-resource?tenant=acme'],
            WellKnownUri::forProtectedResource('https://mcp.example.com?tenant=acme'),
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

        WellKnownUri::forAuthorizationServer($uri);
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

        WellKnownUri::forProtectedResource('mcp.example.com');
    }
}
