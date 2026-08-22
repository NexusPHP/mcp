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
    }

    /**
     * The `string $uri` parameter type widens past the
     * literal-string types PHPStan would otherwise pin on the data-provider
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

        yield 'scheme starts with digit' => ['1http://example.com', 'resource "uri"', '/\Aresource "uri" must be a valid RFC 3986/'];

        yield 'embedded space' => ['file:///path with space', 'resource "uri"', '/\Aresource "uri" must contain only ASCII printable/'];

        yield 'tab character' => ["file:///x\ty", 'resource "uri"', '/\Aresource "uri" must contain only ASCII printable/'];

        yield 'newline' => ["file:///x\n", 'resource "uri"', '/\Aresource "uri" must contain only ASCII printable/'];

        yield 'null byte' => ["file:///x\0", 'resource "uri"', '/\Aresource "uri" must contain only ASCII printable/'];

        yield 'non-ASCII path' => ['file:///résumé', 'resource "uri"', '/\Aresource "uri" must contain only ASCII printable/'];

        yield 'just a colon' => [':rest', 'resource "uri"', '/\Aresource "uri" must be a valid RFC 3986/'];

        yield 'context prefix interpolated' => ['not-a-uri', 'resource contents "uri"', '/\Aresource contents "uri" must be a valid RFC 3986/'];
    }
}
