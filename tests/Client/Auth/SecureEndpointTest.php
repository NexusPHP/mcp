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
}
