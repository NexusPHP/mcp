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

namespace Nexus\Mcp\Tests\Core\UriTemplate;

use Nexus\Mcp\Core\UriTemplate\Validator;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(Validator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ValidatorTest extends AbstractMcpTestCase
{
    #[DataProvider('provideAcceptsMatchableTemplateCases')]
    public function testAcceptsMatchableTemplate(string $template): void
    {
        $this->expectNotToPerformAssertions();

        Validator::validate($template, 'ResourceTemplate');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptsMatchableTemplateCases(): iterable
    {
        yield 'literal only' => ['file:///etc/passwd'];

        yield 'single simple expression' => ['file:///{path}'];

        yield 'multiple expressions with literal separator' => ['weather://{city}/{day}'];

        yield 'repeated variable name' => ['mirror://{x}/{x}'];

        yield 'underscore name' => ['file:///{_root}'];

        yield 'alphanumeric name' => ['file:///{path1}'];

        yield 'name at the 32-character bound' => [\sprintf('file:///{%s}', str_repeat('a', 32))];
    }

    #[DataProvider('provideRejectsLevel2OrHigherExpressionCases')]
    public function testRejectsLevel2OrHigherExpression(string $template): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/^ResourceTemplate URI template must use only Level 1 `{name}` expressions/',
        );

        Validator::validate($template, 'ResourceTemplate');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsLevel2OrHigherExpressionCases(): iterable
    {
        yield 'reserved expansion' => ['file:///{+path}'];

        yield 'fragment expansion' => ['file:///{#path}'];

        yield 'path segment' => ['file://{/path}'];

        yield 'query string' => ['file:///{?path}'];

        yield 'query continuation' => ['file:///{&path}'];

        yield 'path style' => ['file:///{;path}'];

        yield 'label expansion' => ['file:///{.path}'];

        yield 'prefix modifier' => ['file:///{path:3}'];

        yield 'explode modifier' => ['file:///{path*}'];

        yield 'multi-var list' => ['file:///{a,b}'];

        yield 'empty body' => ['file:///{}'];

        yield 'name starts with digit' => ['file:///{0name}'];

        yield 'name contains dash' => ['file:///{a-b}'];

        yield 'name contains dot' => ['file:///{a.b}'];

        yield 'name contains space' => ['file:///{a b}'];
    }

    public function testRejectsAVariableNamePastTheGroupNameBound(): void
    {
        $name = str_repeat('a', 33);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'ResourceTemplate URI template variable names must be at most 32 characters, got "file:///{%s}".',
            $name,
        ));

        Validator::validate(\sprintf('file:///{%s}', $name), 'ResourceTemplate');
    }

    public function testRejectsAdjacentExpressions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/^ResourceTemplate URI template needs literal text between expressions, got "file:\/\/\/{a}{b}"\.$/',
        );

        Validator::validate('file:///{a}{b}', 'ResourceTemplate');
    }

    public function testContextLabelIsInterpolatedIntoErrorMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Custom label URI template must use only Level 1 `{name}` expressions/');

        Validator::validate('file:///{+path}', 'Custom label');
    }

    /**
     * @param non-empty-string $uriTemplate
     */
    #[DataProvider('provideValidateShapeAcceptsValidUriTemplateCases')]
    public function testValidateShapeAcceptsValidUriTemplate(string $uriTemplate): void
    {
        $this->expectNotToPerformAssertions();

        Validator::validateShape($uriTemplate, 'resource template "uriTemplate"');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideValidateShapeAcceptsValidUriTemplateCases(): iterable
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
     * The `string $uriTemplate` parameter type widens past the
     * literal-string types that PHPStan would otherwise pin on the data-provider
     * values, so the validator's `@phpstan-assert non-empty-string $uriTemplate`
     * doesn't produce always-false / already-narrowed errors at the call site.
     *
     * @param non-empty-string $context
     * @param non-empty-string $messagePattern
     */
    #[DataProvider('provideValidateShapeRejectsInvalidUriTemplateCases')]
    public function testValidateShapeRejectsInvalidUriTemplate(
        string $uriTemplate,
        string $context,
        string $messagePattern,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($messagePattern);

        Validator::validateShape($uriTemplate, $context);
    }

    /**
     * @return iterable<string, array{string, non-empty-string, non-empty-string}>
     */
    public static function provideValidateShapeRejectsInvalidUriTemplateCases(): iterable
    {
        yield 'empty string' => [
            '',
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be a non-empty string\./',
        ];

        yield 'no scheme' => [
            'my-template/{name}',
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be a valid RFC 6570/',
        ];

        yield 'scheme starts with digit' => [
            '1http://example.com/{x}',
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be a valid RFC 6570/',
        ];

        yield 'embedded space' => [
            'file:///path/{name with space}',
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be ASCII printable/',
        ];

        yield 'tab character' => [
            "file:///x\t{y}",
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be ASCII printable/',
        ];

        yield 'newline' => [
            "file:///x\n{y}",
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be ASCII printable/',
        ];

        yield 'null byte' => [
            "file:///x\0{y}",
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be ASCII printable/',
        ];

        yield 'non-ASCII path' => [
            'file:///résumé/{x}',
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be ASCII printable/',
        ];

        yield 'unbalanced opening brace' => [
            'file:///tmp/{name',
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be a valid RFC 6570/',
        ];

        yield 'unbalanced closing brace' => [
            'file:///tmp/name}',
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be a valid RFC 6570/',
        ];

        yield 'empty expression' => [
            'file:///tmp/{}',
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be a valid RFC 6570/',
        ];

        yield 'nested braces' => [
            'file:///tmp/{{x}}',
            'resource template "uriTemplate"',
            '/\Aresource template "uriTemplate" must be a valid RFC 6570/',
        ];

        yield 'context prefix interpolated' => [
            'no-scheme',
            'resource template reference "uri"',
            '/\Aresource template reference "uri" must be a valid RFC 6570/',
        ];
    }
}
