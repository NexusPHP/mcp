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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ResourceIdentifier::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResourceIdentifierTest extends AbstractMcpTestCase
{
    #[DataProvider('provideCanonicalisationCases')]
    public function testCanonicalisation(string $uri, string $expected): void
    {
        self::assertSame($expected, (new ResourceIdentifier($uri))->value);
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
        self::assertSame($expected, (new ResourceIdentifier('https://mcp.example.com/mcp'))->matchesAudience($audience));
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
        self::assertSame($expected, (new ResourceIdentifier('https://mcp.example.com/mcp'))->sharesOriginWith($uri));
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

    #[DataProvider('provideCoversCases')]
    public function testCovers(string $resource, string $uri, bool $expected): void
    {
        self::assertSame($expected, (new ResourceIdentifier($resource))->covers($uri));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideCoversCases(): iterable
    {
        $resource = 'https://mcp.example.com/tenant-a/mcp';

        yield 'the resource itself' => [$resource, 'https://mcp.example.com/tenant-a/mcp', true];

        yield 'a path under the resource' => [$resource, 'https://mcp.example.com/tenant-a/mcp/v2', true];

        yield 'the resource with a trailing slash' => [$resource, 'https://mcp.example.com/tenant-a/mcp/', true];

        yield 'the resource with a query' => [$resource, 'https://mcp.example.com/tenant-a/mcp?page=2', true];

        yield 'the default port spelled out' => [$resource, 'https://mcp.example.com:443/tenant-a/mcp', true];

        yield 'another tenant on the same origin' => [$resource, 'https://mcp.example.com/tenant-b/mcp', false];

        yield 'a sibling path sharing the prefix bytes' => [$resource, 'https://mcp.example.com/tenant-a/mcpx', false];

        yield 'a parent path' => [$resource, 'https://mcp.example.com/tenant-a', false];

        yield 'the origin root' => [$resource, 'https://mcp.example.com/', false];

        yield 'another host' => [$resource, 'https://attacker.example.com/tenant-a/mcp', false];

        yield 'a scheme downgrade' => [$resource, 'http://mcp.example.com/tenant-a/mcp', false];

        yield 'a non-default port' => [$resource, 'https://mcp.example.com:8443/tenant-a/mcp', false];

        yield 'a relative URL names no resource' => [$resource, '/tenant-a/mcp', false];

        yield 'userinfo is never trusted' => [$resource, 'https://evil@mcp.example.com/tenant-a/mcp', false];

        yield 'a trailing-slash resource covers its subtree' => ['https://mcp.example.com/mcp/', 'https://mcp.example.com/mcp/tools', true];

        yield 'a trailing-slash resource covers its slashless self' => ['https://mcp.example.com/mcp/', 'https://mcp.example.com/mcp', true];

        yield 'a root resource covers every path on its origin' => ['https://mcp.example.com', 'https://mcp.example.com/anything/at/all', true];

        yield 'a root resource covers the bare origin' => ['https://mcp.example.com', 'https://mcp.example.com', true];

        yield 'a root resource does not cover another origin' => ['https://mcp.example.com', 'https://other.example.com/x', false];

        yield 'a percent-encoded dot-segment traversal' => [$resource, 'https://mcp.example.com/tenant-a/mcp/%2e%2e/tenant-b/mcp', false];

        yield 'an uppercase percent-encoded traversal' => [$resource, 'https://mcp.example.com/tenant-a/mcp/%2E%2E/tenant-b/mcp', false];

        yield 'a literal dot-dot segment' => [$resource, 'https://mcp.example.com/tenant-a/mcp/../tenant-b/mcp', false];

        yield 'a literal single-dot segment' => [$resource, 'https://mcp.example.com/tenant-a/mcp/./tools', false];

        yield 'a trailing dot-dot segment' => [$resource, 'https://mcp.example.com/tenant-a/mcp/..', false];

        yield 'a percent-encoded slash' => [$resource, 'https://mcp.example.com/tenant-a/mcp%2ftools', false];

        yield 'a percent-encoded backslash' => [$resource, 'https://mcp.example.com/tenant-a/mcp/%5c../tenant-b', false];

        yield 'a literal backslash' => [$resource, 'https://mcp.example.com/tenant-a/mcp/\..\tenant-b', false];
    }

    #[DataProvider('provideTheOriginIsExposedCases')]
    public function testTheOriginIsExposed(string $uri, string $origin): void
    {
        self::assertSame($origin, (new ResourceIdentifier($uri))->origin);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTheOriginIsExposedCases(): iterable
    {
        yield 'a path is not part of the origin' => ['https://mcp.example.com/mcp', 'https://mcp.example.com'];

        yield 'the default port is elided' => ['https://mcp.example.com:443/mcp', 'https://mcp.example.com'];

        yield 'a non-default port is kept' => ['https://mcp.example.com:8443/mcp', 'https://mcp.example.com:8443'];

        yield 'the host is lowercased' => ['https://MCP.Example.com/mcp', 'https://mcp.example.com'];

        yield 'a query is not part of the origin' => ['https://mcp.example.com/mcp?a=b', 'https://mcp.example.com'];
    }

    public function testBoundsAndEscapesAHostileUriInTheRefusal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'The MCP server resource identifier must be an absolute URI carrying no fragment or userinfo, "%s..." given.',
            str_repeat('u', 253),
        ));

        new ResourceIdentifier(str_repeat('u', 300)."\x1b");
    }
}
