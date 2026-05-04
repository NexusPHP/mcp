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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Validation\Rfc6570UriTemplateValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Rfc6570UriTemplateValidator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class Rfc6570UriTemplateValidatorTest extends TestCase
{
    /**
     * @param non-empty-string $uriTemplate
     */
    #[DataProvider('provideAcceptsValidUriTemplateCases')]
    public function testAcceptsValidUriTemplate(string $uriTemplate): void
    {
        $this->expectNotToPerformAssertions();

        Rfc6570UriTemplateValidator::validate($uriTemplate, 'ResourceTemplate');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideAcceptsValidUriTemplateCases(): iterable
    {
        yield 'plain file uri' => ['file:///tmp/sample'];

        yield 'plain https uri' => ['https://example.com/path'];

        yield 'simple expansion' => ['file:///tmp/{name}'];

        yield 'multiple expansions' => ['https://api.example.com/{user}/repos/{repo}'];

        yield 'reserved expansion operator' => ['https://example.com/{+path}'];

        yield 'fragment expansion operator' => ['https://example.com/page{#section}'];

        yield 'query expansion operator' => ['https://example.com/search{?q,limit}'];

        yield 'form-style continuation operator' => ['https://example.com/path?fixed=1{&extra}'];

        yield 'expansion with explode modifier' => ['https://example.com/{path*}'];

        yield 'expansion with prefix modifier' => ['https://example.com/{var:3}'];
    }

    /**
     * The `string $uriTemplate` parameter type is deliberate: it widens past the
     * literal-string types PHPStan would otherwise pin on the data-provider
     * values, so the validator's `@phpstan-assert non-empty-string $uriTemplate`
     * doesn't produce always-false / already-narrowed errors at the call site.
     *
     * @param non-empty-string $context
     * @param non-empty-string $messagePattern
     */
    #[DataProvider('provideRejectsInvalidUriTemplateCases')]
    public function testRejectsInvalidUriTemplate(string $uriTemplate, string $context, string $messagePattern): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches($messagePattern);

        Rfc6570UriTemplateValidator::validate($uriTemplate, $context);
    }

    /**
     * @return iterable<string, array{string, non-empty-string, non-empty-string}>
     */
    public static function provideRejectsInvalidUriTemplateCases(): iterable
    {
        yield 'empty string' => ['', 'ResourceTemplate', '/\AResourceTemplate URI template must be a non-empty string\./'];

        yield 'no scheme' => ['my-template/{name}', 'ResourceTemplate', '/\AResourceTemplate URI template must be a valid RFC 6570/'];

        yield 'scheme starts with digit' => ['1http://example.com/{x}', 'ResourceTemplate', '/\AResourceTemplate URI template must be a valid RFC 6570/'];

        yield 'embedded space' => ['file:///path/{name with space}', 'ResourceTemplate', '/\AResourceTemplate URI template must contain only ASCII printable/'];

        yield 'tab character' => ["file:///x\t{y}", 'ResourceTemplate', '/\AResourceTemplate URI template must contain only ASCII printable/'];

        yield 'newline' => ["file:///x\n{y}", 'ResourceTemplate', '/\AResourceTemplate URI template must contain only ASCII printable/'];

        yield 'null byte' => ["file:///x\0{y}", 'ResourceTemplate', '/\AResourceTemplate URI template must contain only ASCII printable/'];

        yield 'non-ASCII path' => ['file:///résumé/{x}', 'ResourceTemplate', '/\AResourceTemplate URI template must contain only ASCII printable/'];

        yield 'unbalanced opening brace' => ['file:///tmp/{name', 'ResourceTemplate', '/\AResourceTemplate URI template must be a valid RFC 6570/'];

        yield 'unbalanced closing brace' => ['file:///tmp/name}', 'ResourceTemplate', '/\AResourceTemplate URI template must be a valid RFC 6570/'];

        yield 'empty expression' => ['file:///tmp/{}', 'ResourceTemplate', '/\AResourceTemplate URI template must be a valid RFC 6570/'];

        yield 'nested braces' => ['file:///tmp/{{x}}', 'ResourceTemplate', '/\AResourceTemplate URI template must be a valid RFC 6570/'];

        yield 'context prefix interpolated' => ['no-scheme', 'ResourceTemplateReference', '/\AResourceTemplateReference URI template must be a valid RFC 6570/'];
    }
}
