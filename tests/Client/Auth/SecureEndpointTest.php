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

use Nexus\Mcp\Client\Auth\SecureEndpoint;
use Nexus\Mcp\Client\Exception\InsecureAuthorizationEndpointException;
use Nexus\Mcp\Client\Exception\UntrustedAuthorizationMetadataException;
use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SecureEndpoint::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class SecureEndpointTest extends TestCase
{
    #[DataProvider('provideAcceptedUrlsCases')]
    public function testAcceptedUrls(string $url): void
    {
        $this->expectNotToPerformAssertions();

        SecureEndpoint::verify($url, 'token endpoint');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptedUrlsCases(): iterable
    {
        yield 'HTTPS is accepted' => ['https://auth.example.com/token'];

        yield 'an uppercase scheme is accepted' => ['HTTPS://auth.example.com/token'];

        yield 'loopback by name is accepted' => ['http://localhost:9000/token'];

        yield 'loopback by name is matched case-insensitively' => ['http://LocalHost:9000/token'];

        yield 'the loopback address is accepted' => ['http://127.0.0.1:9000/token'];

        yield 'any address in the loopback block is accepted' => ['http://127.13.5.9/token'];

        yield 'the IPv6 loopback address is accepted' => ['http://[::1]:9000/token'];
    }

    #[DataProvider('provideRejectedUrlsCases')]
    public function testRejectedUrls(string $url): void
    {
        $this->expectException(InsecureAuthorizationEndpointException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The token endpoint must be served over HTTPS or from a loopback host, "%s" given.',
            $url,
        ));

        SecureEndpoint::verify($url, 'token endpoint');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectedUrlsCases(): iterable
    {
        yield 'plain HTTP to a remote host is rejected' => ['http://auth.example.com/token'];

        yield 'a host merely starting with the loopback name is rejected' => ['http://localhost.attacker.example/token'];

        yield 'a host merely starting with the loopback address is rejected' => ['http://127.0.0.1.attacker.example/token'];

        yield 'a host merely ending with the loopback address is rejected' => ['http://attacker127.0.0.1/token'];

        yield 'a near-miss of the loopback block is rejected' => ['http://128.0.0.1/token'];

        yield 'a non-HTTP scheme is rejected' => ['ftp://auth.example.com/token'];
    }

    #[DataProvider('provideMalformedUrlsCases')]
    public function testMalformedUrls(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('The token endpoint must be an absolute URL, "%s" given.', $url));

        SecureEndpoint::verify($url, 'token endpoint');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedUrlsCases(): iterable
    {
        yield 'an empty string is not a URL' => [''];

        yield 'a bare authority has no scheme' => ['auth.example.com/token'];

        yield 'a scheme with no host is not an endpoint' => ['file:///tmp/token'];
    }

    public function testAnAdvertisedHttpsUrlIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        SecureEndpoint::verifyAdvertised('https://auth.example.com/token', 'token endpoint', self::resource());
    }

    #[DataProvider('provideAnAdvertisedCleartextUrlIsRefusedForARemoteMcpServerCases')]
    public function testAnAdvertisedCleartextUrlIsRefusedForARemoteMcpServer(string $url): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The authorization metadata cannot be trusted because the token endpoint "%s" is not served over HTTPS.',
            $url,
        ));

        SecureEndpoint::verifyAdvertised($url, 'token endpoint', self::resource());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnAdvertisedCleartextUrlIsRefusedForARemoteMcpServerCases(): iterable
    {
        yield 'a remote cleartext host is refused' => ['http://auth.example.com/token'];

        yield 'the loopback exemption an operator earns is not inherited' => ['http://127.0.0.1:6379/token'];

        yield 'nor is it inherited by name' => ['http://localhost:6379/token'];

        yield 'a link-local address is refused' => ['http://169.254.169.254/token'];
    }

    public function testALoopbackMcpServerLetsAnAdvertisedLoopbackUrlThrough(): void
    {
        $this->expectNotToPerformAssertions();

        SecureEndpoint::verifyAdvertised(
            'http://127.0.0.1:9000/token',
            'token endpoint',
            self::resource('http://localhost:9000/mcp'),
        );
    }

    public function testALoopbackMcpServerStillRefusesAnAdvertisedRemoteCleartextUrl(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the token endpoint "http://auth.example.com/token" is not served over HTTPS.');

        SecureEndpoint::verifyAdvertised(
            'http://auth.example.com/token',
            'token endpoint',
            self::resource('http://localhost:9000/mcp'),
        );
    }

    #[DataProvider('provideTheSameOriginIsAcceptedCases')]
    public function testTheSameOriginIsAccepted(string $url, string $resource): void
    {
        $this->expectNotToPerformAssertions();

        SecureEndpoint::verifySameOrigin($url, 'advertised metadata URL', self::resource($resource));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTheSameOriginIsAcceptedCases(): iterable
    {
        yield 'another path on the same origin' => ['https://mcp.example.com/prm', 'https://mcp.example.com/mcp'];

        yield 'the default HTTPS port spelled out' => ['https://mcp.example.com:443/prm', 'https://mcp.example.com/mcp'];

        yield 'the default HTTP port spelled out' => ['http://mcp.example.com:80/prm', 'http://mcp.example.com/mcp'];

        yield 'the host cased differently' => ['https://MCP.Example.com/prm', 'https://mcp.example.com/mcp'];

        yield 'a matching non-default port' => ['https://mcp.example.com:8443/prm', 'https://mcp.example.com:8443/mcp'];
    }

    #[DataProvider('provideACrossOriginUrlIsRefusedCases')]
    public function testACrossOriginUrlIsRefused(string $url, string $resource, string $origin): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The authorization metadata cannot be trusted because the advertised metadata URL "%s" is served from "%s" rather than by the MCP server it describes.',
            $url,
            $origin,
        ));

        SecureEndpoint::verifySameOrigin($url, 'advertised metadata URL', self::resource($resource));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideACrossOriginUrlIsRefusedCases(): iterable
    {
        yield 'another host' => [
            'https://attacker.example.com/prm',
            'https://mcp.example.com/mcp',
            'https://attacker.example.com',
        ];

        yield 'another scheme' => [
            'http://mcp.example.com/prm',
            'https://mcp.example.com/mcp',
            'http://mcp.example.com',
        ];

        yield 'a port the MCP server does not answer on' => [
            'https://mcp.example.com:8443/prm',
            'https://mcp.example.com/mcp',
            'https://mcp.example.com:8443',
        ];

        yield 'no port where the MCP server names one' => [
            'https://mcp.example.com/prm',
            'https://mcp.example.com:8443/mcp',
            'https://mcp.example.com',
        ];

        yield 'two different non-default ports' => [
            'https://mcp.example.com:8443/prm',
            'https://mcp.example.com:9443/mcp',
            'https://mcp.example.com:8443',
        ];
    }

    public function testAnHttpsMetadataDocumentUrlWithAPathIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        SecureEndpoint::verifyClientIdMetadataDocumentUrl('https://app.example.com/oauth/client.json');
    }

    #[DataProvider('provideAMetadataDocumentUrlOffTheSpecsShapeIsRefusedCases')]
    public function testAMetadataDocumentUrlOffTheSpecsShapeIsRefused(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The Client ID Metadata Document URL must be an HTTPS URL carrying a path component, "%s" given.',
            $url,
        ));

        SecureEndpoint::verifyClientIdMetadataDocumentUrl($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAMetadataDocumentUrlOffTheSpecsShapeIsRefusedCases(): iterable
    {
        yield 'cleartext is refused' => ['http://app.example.com/client.json'];

        yield 'loopback earns no exemption' => ['http://localhost:3000/client.json'];

        yield 'a bare host carries no path' => ['https://app.example.com'];

        yield 'a root path is no path' => ['https://app.example.com/'];
    }

    private static function resource(string $uri = 'https://mcp.example.com/mcp'): ResourceIdentifier
    {
        return new ResourceIdentifier($uri);
    }
}
