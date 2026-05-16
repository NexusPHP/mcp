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

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\UriTemplate\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Validator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ValidatorTest extends TestCase
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
    }

    #[DataProvider('provideRejectsLevel2OrHigherExpressionCases')]
    public function testRejectsLevel2OrHigherExpression(string $template): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^ResourceTemplate URI template must use only RFC 6570 Level 1 simple-name expressions/');

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

    public function testRejectsAdjacentExpressions(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^ResourceTemplate URI template must include literal text between adjacent expressions, got "file:\/\/\/{a}{b}"\.$/');

        Validator::validate('file:///{a}{b}', 'ResourceTemplate');
    }

    public function testContextLabelIsInterpolatedIntoErrorMessage(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageMatches('/^Custom label URI template must use only RFC 6570 Level 1 simple-name expressions/');

        Validator::validate('file:///{+path}', 'Custom label');
    }
}
