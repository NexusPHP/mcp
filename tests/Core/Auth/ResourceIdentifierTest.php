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

namespace Nexus\Mcp\Tests\Core\Auth;

use Nexus\Mcp\Core\Auth\ResourceIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResourceIdentifier::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResourceIdentifierTest extends TestCase
{
    #[DataProvider('provideCanonicalisationCases')]
    public function testCanonicalisation(string $uri, string $expected): void
    {
        self::assertSame($expected, new ResourceIdentifier($uri)->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideCanonicalisationCases(): iterable
    {
        yield 'a canonical URI passes through' => ['https://mcp.example.com/mcp', 'https://mcp.example.com/mcp'];

        yield 'a host-only URI passes through' => ['https://mcp.example.com', 'https://mcp.example.com'];

        yield 'a port is kept' => ['https://mcp.example.com:8443', 'https://mcp.example.com:8443'];

        yield 'a nested path is kept' => ['https://mcp.example.com/server/mcp', 'https://mcp.example.com/server/mcp'];

        yield 'the scheme is lowercased' => ['HTTPS://mcp.example.com/mcp', 'https://mcp.example.com/mcp'];

        yield 'the host is lowercased' => ['https://MCP.Example.COM/mcp', 'https://mcp.example.com/mcp'];

        yield 'the path keeps its case' => ['https://MCP.Example.COM/McP', 'https://mcp.example.com/McP'];

        yield 'a bare root path is dropped' => ['https://mcp.example.com/', 'https://mcp.example.com'];

        yield 'a trailing slash below the root is kept' => ['https://mcp.example.com/mcp/', 'https://mcp.example.com/mcp/'];

        yield 'a query is kept' => ['https://mcp.example.com/mcp?tenant=a', 'https://mcp.example.com/mcp?tenant=a'];

        yield 'a loopback endpoint is accepted' => ['http://localhost:3000/mcp', 'http://localhost:3000/mcp'];

        yield 'the default HTTPS port is elided' => ['https://mcp.example.com:443/mcp', 'https://mcp.example.com/mcp'];

        yield 'the default HTTP port is elided' => ['http://mcp.example.com:80/mcp', 'http://mcp.example.com/mcp'];

        yield 'a non-default port is kept for HTTP' => ['http://mcp.example.com:8080/mcp', 'http://mcp.example.com:8080/mcp'];

        yield 'a port is kept for a scheme with no default' => ['ftp://mcp.example.com:21/mcp', 'ftp://mcp.example.com:21/mcp'];
    }

    #[DataProvider('provideRejectedUrisCases')]
    public function testRejectedUris(string $uri): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The MCP server resource identifier must be an absolute URI carrying no fragment or userinfo, "%s" given.',
            $uri,
        ));

        new ResourceIdentifier($uri);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectedUrisCases(): iterable
    {
        yield 'an empty string is not a URI' => [''];

        yield 'a bare authority has no scheme' => ['mcp.example.com'];

        yield 'a relative path has no scheme' => ['/mcp'];

        yield 'a fragment is forbidden' => ['https://mcp.example.com#fragment'];

        yield 'a scheme with no host is not a resource' => ['file:///tmp/mcp'];

        yield 'userinfo is forbidden' => ['https://evil.example.net@mcp.example.com/mcp'];

        yield 'a userinfo password is forbidden' => ['https://user:pass@mcp.example.com/mcp'];
    }

    /**
     * @param list<string> $audience
     */
    #[DataProvider('provideMatchesAudienceCases')]
    public function testMatchesAudience(array $audience, bool $expected): void
    {
        self::assertSame($expected, new ResourceIdentifier('https://mcp.example.com/mcp')->matchesAudience($audience));
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function provideMatchesAudienceCases(): iterable
    {
        yield 'an empty audience names nothing' => [[], false];

        yield 'an exact match is accepted' => [['https://mcp.example.com/mcp'], true];

        yield 'a match among several is accepted' => [['https://other.example.com', 'https://mcp.example.com/mcp'], true];

        yield 'an uppercase host still names the resource' => [['https://MCP.Example.COM/mcp'], true];

        yield 'a bare root path does not name a path-scoped resource' => [['https://mcp.example.com'], false];

        yield 'a different path is rejected' => [['https://mcp.example.com/other'], false];

        yield 'a different host is rejected' => [['https://other.example.com/mcp'], false];

        yield 'an unparsable audience value is rejected' => [['not-a-uri'], false];

        yield 'an audience smuggling the resource into userinfo is rejected' => [['https://mcp.example.com/mcp@attacker.example/mcp'], false];

        yield 'an audience naming this resource in userinfo is rejected' => [['https://evil.example.net@mcp.example.com/mcp'], false];

        yield 'the explicit default port still names the resource' => [['https://mcp.example.com:443/mcp'], true];

        yield 'another scheme does not name the resource' => [['http://mcp.example.com/mcp'], false];
    }

    #[DataProvider('provideSharesOriginWithCases')]
    public function testSharesOriginWith(string $uri, bool $expected): void
    {
        self::assertSame($expected, new ResourceIdentifier('https://mcp.example.com/mcp')->sharesOriginWith($uri));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideSharesOriginWithCases(): iterable
    {
        yield 'another path on the same origin' => ['https://mcp.example.com/.well-known/prm', true];

        yield 'the same URL' => ['https://mcp.example.com/mcp', true];

        yield 'the default port spelled out' => ['https://mcp.example.com:443/prm', true];

        yield 'the host cased differently' => ['https://MCP.Example.com/prm', true];

        yield 'another host' => ['https://attacker.example.com/prm', false];

        yield 'another scheme' => ['http://mcp.example.com/prm', false];

        yield 'a non-default port' => ['https://mcp.example.com:8443/prm', false];

        yield 'a relative URL names no origin' => ['/prm', false];

        yield 'a URL carrying userinfo is never trusted' => ['https://evil@mcp.example.com/prm', false];
    }

    #[DataProvider('provideTheOriginAndHostAreExposedCases')]
    public function testTheOriginAndHostAreExposed(string $uri, string $origin, string $host): void
    {
        $resource = new ResourceIdentifier($uri);

        self::assertSame($origin, $resource->origin);
        self::assertSame($host, $resource->host);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideTheOriginAndHostAreExposedCases(): iterable
    {
        yield 'a path is not part of the origin' => ['https://mcp.example.com/mcp', 'https://mcp.example.com', 'mcp.example.com'];

        yield 'the default port is elided' => ['https://mcp.example.com:443/mcp', 'https://mcp.example.com', 'mcp.example.com'];

        yield 'a non-default port is kept' => ['https://mcp.example.com:8443/mcp', 'https://mcp.example.com:8443', 'mcp.example.com'];

        yield 'the host is lowercased' => ['https://MCP.Example.com/mcp', 'https://mcp.example.com', 'mcp.example.com'];

        yield 'a query is not part of the origin' => ['https://mcp.example.com/mcp?a=b', 'https://mcp.example.com', 'mcp.example.com'];
    }
}
