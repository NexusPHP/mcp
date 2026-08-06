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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SecureEndpoint::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class SecureEndpointTest extends AbstractMcpTestCase
{
    #[DataProvider('provideAcceptedUrlsCases')]
    public function testAcceptedUrls(string $url): void
    {
        $this->expectNotToPerformAssertions();

        SecureEndpoint::verifyRedirectUri($url);
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
            'The redirect URI must be served over HTTPS or from a loopback host, "%s" given.',
            $url,
        ));

        SecureEndpoint::verifyRedirectUri($url);
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
        $this->expectExceptionMessageIs(\sprintf('The redirect URI must be an absolute URL, "%s" given.', $url));

        SecureEndpoint::verifyRedirectUri($url);
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

    #[DataProvider('provideAnAuthorizationServerUrlOverHttpsIsAcceptedCases')]
    public function testAnAuthorizationServerUrlOverHttpsIsAccepted(string $url): void
    {
        $this->expectNotToPerformAssertions();

        SecureEndpoint::verifyAuthorizationServerUrl($url, 'token endpoint');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnAuthorizationServerUrlOverHttpsIsAcceptedCases(): iterable
    {
        yield 'a public host is accepted' => ['https://auth.example.com/token'];

        yield 'an uppercase scheme is accepted' => ['HTTPS://auth.example.com/token'];

        yield 'a query is left alone' => ['https://auth.example.com/token?tenant=a'];

        // Where the URL leads is the operator's business. This guard is about the transport it names.
        yield 'a loopback address over HTTPS is accepted' => ['https://127.0.0.1:9000/token'];

        yield 'a private-network address over HTTPS is accepted' => ['https://10.0.0.5:8443/token'];
    }

    #[DataProvider('provideAnAuthorizationServerUrlThatIsNotHttpsIsRefusedCases')]
    public function testAnAuthorizationServerUrlThatIsNotHttpsIsRefused(string $url): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The authorization metadata cannot be trusted because the token endpoint "%s" is not an absolute HTTPS URL.',
            $url,
        ));

        SecureEndpoint::verifyAuthorizationServerUrl($url, 'token endpoint');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnAuthorizationServerUrlThatIsNotHttpsIsRefusedCases(): iterable
    {
        yield 'a remote cleartext host is refused' => ['http://auth.example.com/token'];

        // The spec exempts only the redirect URI from HTTPS, so by default an authorization server on
        // loopback earns nothing from the MCP server also being there. Opting in changes that, which the
        // cases below cover.
        yield 'a loopback address earns no exemption' => ['http://127.0.0.1:9000/token'];

        yield 'nor does loopback by name' => ['http://localhost:9000/token'];

        yield 'a non-HTTP scheme is refused' => ['ftp://auth.example.com/token'];

        yield 'a URL that is not absolute is refused' => ['/token'];

        yield 'an empty string is refused' => [''];

        yield 'a scheme with no host is refused' => ['file:///tmp/token'];
    }

    #[DataProvider('provideAnAuthorizationServerUrlOverCleartextLoopbackIsAcceptedWhenOptedInCases')]
    public function testAnAuthorizationServerUrlOverCleartextLoopbackIsAcceptedWhenOptedIn(string $url): void
    {
        $this->expectNotToPerformAssertions();

        SecureEndpoint::verifyAuthorizationServerUrl($url, 'token endpoint', true);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnAuthorizationServerUrlOverCleartextLoopbackIsAcceptedWhenOptedInCases(): iterable
    {
        yield 'loopback by address' => ['http://127.0.0.1:9000/token'];

        yield 'loopback by name' => ['http://localhost:9000/token'];

        yield 'loopback over IPv6' => ['http://[::1]:9000/token'];

        yield 'anywhere in 127.0.0.0/8' => ['http://127.9.9.9:9000/token'];

        yield 'an uppercase scheme still resolves' => ['HTTP://LOCALHOST:9000/token'];
    }

    #[DataProvider('provideOptingIntoLoopbackAdmitsNothingElseCases')]
    public function testOptingIntoLoopbackAdmitsNothingElse(string $url): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The authorization metadata cannot be trusted because the token endpoint "%s" is not an absolute HTTPS URL.',
            $url,
        ));

        SecureEndpoint::verifyAuthorizationServerUrl($url, 'token endpoint', true);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOptingIntoLoopbackAdmitsNothingElseCases(): iterable
    {
        yield 'a remote cleartext host is still refused' => ['http://auth.example.com/token'];

        // A private-network address is not loopback: it leaves the host.
        yield 'a private-network address is still refused' => ['http://10.0.0.5:8443/token'];

        yield 'a host merely starting with the loopback name is still refused' => ['http://localhost.evil.example.com/token'];

        yield 'a non-HTTP scheme is still refused' => ['ftp://127.0.0.1:9000/token'];

        yield 'a URL that is not absolute is still refused' => ['/token'];
    }

    public function testOptingIntoLoopbackStillRefusesAFragment(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the authorization endpoint "http://127.0.0.1:9000/authorize#done" carries a fragment.');

        SecureEndpoint::verifyAuthorizationServerUrl('http://127.0.0.1:9000/authorize#done', 'authorization endpoint', true);
    }

    public function testAnAuthorizationServerUrlCarryingAFragmentIsRefused(): void
    {
        $this->expectException(UntrustedAuthorizationMetadataException::class);
        $this->expectExceptionMessageIs('The authorization metadata cannot be trusted because the authorization endpoint "https://auth.example.com/authorize#done" carries a fragment.');

        SecureEndpoint::verifyAuthorizationServerUrl('https://auth.example.com/authorize#done', 'authorization endpoint');
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

    public function testAMetadataDocumentUrlThatIsNotAbsoluteIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The Client ID Metadata Document URL must be an absolute URL, "client.json" given.');

        SecureEndpoint::verifyClientIdMetadataDocumentUrl('client.json');
    }
}
