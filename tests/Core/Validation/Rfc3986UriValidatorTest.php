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

namespace Nexus\Mcp\Tests\Core\Validation;

use Nexus\Mcp\Core\Validation\Rfc3986UriValidator;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(Rfc3986UriValidator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class Rfc3986UriValidatorTest extends AbstractMcpTestCase
{
    /**
     * @param non-empty-string $uri
     */
    #[DataProvider('provideAcceptsValidUriCases')]
    public function testAcceptsValidUri(string $uri): void
    {
        $this->expectNotToPerformAssertions();

        Rfc3986UriValidator::validate($uri, 'resource "uri"');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideAcceptsValidUriCases(): iterable
    {
        yield 'file uri' => ['file:///tmp/sample'];

        yield 'https uri' => ['https://example.com/path'];

        yield 'custom scheme with plus' => ['git+ssh://example.com/repo.git'];

        yield 'scheme with dot and dash' => ['my.app-1://host'];

        yield 'with query and fragment' => ['https://example.com/path?q=1&r=2#section'];

        yield 'urn-style' => ['urn:isbn:0451450523'];

        yield 'mailto' => ['mailto:user@example.com'];

        yield 'percent-encoded path' => ['https://example.com/r%C3%A9sum%C3%A9'];

        yield 'underscore in the host' => ['https://my_host.example/pay'];

        yield 'userinfo and port' => ['https://user:pw@example.com:8443/p'];

        yield 'empty port' => ['https://example.com:/p'];

        yield 'IPv6 literal' => ['https://[2001:db8::1]:8443/'];

        yield 'IPvFuture literal' => ['https://[v1.fe80::a+en1]/'];

        yield 'IPvFuture literal with an uppercase flag' => ['https://[V1.fe80::a+en1]/'];

        yield 'slash and question mark in query and fragment' => ['https://example.com/p?q=/a?b#f/?g'];

        yield 'data uri' => ['data:image/png;base64,iVBORw0KGgo+/='];

        yield 'absolute path, no authority' => ['urn:/a/b'];
    }

    /**
     * The `string $uri` parameter type widens past the
     * literal-string types that PHPStan would otherwise pin on the data-provider
     * values, so the validator's `@phpstan-assert non-empty-string $uri`
     * doesn't produce always-false / already-narrowed errors at the call site.
     *
     * @param non-empty-string $context
     * @param non-empty-string $messagePattern
     */
    #[DataProvider('provideRejectsInvalidUriCases')]
    public function testRejectsInvalidUri(string $uri, string $context, string $messagePattern): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($messagePattern);

        Rfc3986UriValidator::validate($uri, $context);
    }

    /**
     * @return iterable<string, array{string, non-empty-string, non-empty-string}>
     */
    public static function provideRejectsInvalidUriCases(): iterable
    {
        yield 'empty string' => ['', 'resource "uri"', '/\Aresource "uri" must be a non-empty string\./'];

        yield 'no scheme' => ['my-resource', 'resource "uri"', '/\Aresource "uri" must be a valid RFC 3986/'];

        yield 'scheme starts with digit' => [
            '1http://example.com',
            'resource "uri"',
            '/\Aresource "uri" must be a valid RFC 3986/',
        ];

        yield 'embedded space' => [
            'file:///path with space',
            'resource "uri"',
            '/\Aresource "uri" must be ASCII printable/',
        ];

        yield 'tab character' => [
            "file:///x\ty",
            'resource "uri"',
            '/\Aresource "uri" must be ASCII printable/',
        ];

        yield 'newline' => ["file:///x\n", 'resource "uri"', '/\Aresource "uri" must be ASCII printable/'];

        yield 'null byte' => ["file:///x\0", 'resource "uri"', '/\Aresource "uri" must be ASCII printable/'];

        yield 'non-ASCII path' => [
            'file:///résumé',
            'resource "uri"',
            '/\Aresource "uri" must be ASCII printable/',
        ];

        yield 'just a colon' => [':rest', 'resource "uri"', '/\Aresource "uri" must be a valid RFC 3986/'];

        yield 'context prefix interpolated' => [
            'not-a-uri',
            'resource contents "uri"',
            '/\Aresource contents "uri" must be a valid RFC 3986/',
        ];

        $malformed = static fn(string $uri): array => [
            $uri,
            'resource "uri"',
            \sprintf('/\Aresource "uri" must be a valid RFC 3986 absolute URI, %s given\.\z/', preg_quote(var_export($uri, true), '/')),
        ];

        yield 'a delimiter in the host' => $malformed('https://exa<mple.com/');

        yield 'an unclosed IP literal' => $malformed('https://[broken/');

        yield 'an empty IP literal' => $malformed('https://[]/');

        yield 'an IP literal that is not IPv6' => $malformed('https://[::g]/');

        yield 'an IPv4 address as an IP literal' => $malformed('https://[1.2.3.4]/');

        yield 'a zone identifier in the IP literal' => $malformed('https://[fe80::1%eth0]/');

        yield 'a non-digit port' => $malformed('https://example.com:8a/');

        yield 'a malformed percent-encoding' => $malformed('https://example.com/%zz');

        yield 'a bracket in the query' => $malformed('https://example.com/p?x=[1]');

        yield 'a second fragment' => $malformed('https://example.com/a#b#c');

        yield 'a backslash in the path' => $malformed('https://example.com/a\b');
    }
}
