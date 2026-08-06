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

use Nexus\Mcp\Core\UriTemplate\Matcher;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(Matcher::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MatcherTest extends AbstractMcpTestCase
{
    public function testMatchesSingleVariableAtEnd(): void
    {
        self::assertSame(['path' => 'etc'], self::match('file:///{path}', 'file:///etc'));
    }

    public function testMatchesMultipleDistinctVariables(): void
    {
        self::assertSame(
            ['city' => 'paris', 'day' => 'today'],
            self::match('weather://{city}/{day}', 'weather://paris/today'),
        );
    }

    public function testCapturedValueIsPercentDecoded(): void
    {
        self::assertSame(['path' => 'hello world'], self::match('file:///{path}', 'file:///hello%20world'));
    }

    public function testRepeatedVariableNameEnforcesIdenticalCaptures(): void
    {
        self::assertSame(['x' => 'foo'], self::match('mirror://{x}/{x}', 'mirror://foo/foo'));
        self::assertNull(self::match('mirror://{x}/{x}', 'mirror://foo/bar'));
    }

    public function testTemplateWithoutVariablesMatchesExactly(): void
    {
        self::assertSame([], self::match('file:///etc', 'file:///etc'));
        self::assertNull(self::match('file:///etc', 'file:///bin'));
    }

    public function testCapturedSegmentDoesNotCrossSlashes(): void
    {
        self::assertNull(self::match('file:///{path}', 'file:///etc/cfg'));
    }

    public function testCapturedSegmentDoesNotCrossQueryOrFragment(): void
    {
        self::assertNull(self::match('file:///{path}', 'file:///etc?q=1'));
        self::assertNull(self::match('file:///{path}', 'file:///etc#frag'));
    }

    public function testDifferentLiteralPrefixDoesNotMatch(): void
    {
        self::assertNull(self::match('file:///{path}', 'http://example.com/etc'));
    }

    public function testLevel2PlusExpressionsAreNotSupported(): void
    {
        self::assertNull(self::match('file:///{+path}', 'file:///etc/cfg'));
        self::assertNull(self::match('weather://{?city}', 'weather://?city=paris'));
        self::assertNull(self::match('file:///{/segments*}', 'file:///a/b/c'));
    }

    public function testCommaSeparatedExpressionsAreNotSupported(): void
    {
        self::assertNull(self::match('weather://{city,day}', 'weather://paris,today'));
    }

    /**
     * @param non-empty-string           $template
     * @param null|array<string, string> $expected
     */
    #[DataProvider('provideRegexSpecialCharactersInLiteralAreEscapedCases')]
    public function testRegexSpecialCharactersInLiteralAreEscaped(string $template, string $uri, ?array $expected): void
    {
        self::assertSame($expected, self::match($template, $uri));
    }

    /**
     * @return iterable<string, array{non-empty-string, string, null|array<string, string>}>
     */
    public static function provideRegexSpecialCharactersInLiteralAreEscapedCases(): iterable
    {
        yield 'dot in literal is literal' => ['file:///a.b/{x}', 'file:///a.b/etc', ['x' => 'etc']];

        yield 'dot does not match arbitrary char' => ['file:///a.b/{x}', 'file:///aXb/etc', null];

        yield 'parentheses in literal' => ['weather://(beta)/{city}', 'weather://(beta)/paris', ['city' => 'paris']];

        yield 'trailing literal with regex-special char' => ['weather://{city}.json', 'weather://paris.json', ['city' => 'paris']];

        yield 'trailing dot does not match arbitrary char' => ['weather://{city}.json', 'weather://parisXjson', null];
    }

    /**
     * @param non-empty-string $template
     *
     * @return null|array<string, string>
     */
    private static function match(string $template, string $uri): ?array
    {
        return Matcher::matchCompiled(Matcher::compile($template), $uri);
    }
}
