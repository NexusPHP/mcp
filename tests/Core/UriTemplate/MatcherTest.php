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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Matcher::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MatcherTest extends TestCase
{
    public function testMatchesSingleVariableAtEnd(): void
    {
        self::assertSame(['path' => 'etc'], Matcher::match('file:///{path}', 'file:///etc'));
    }

    public function testMatchesMultipleDistinctVariables(): void
    {
        self::assertSame(
            ['city' => 'paris', 'day' => 'today'],
            Matcher::match('weather://{city}/{day}', 'weather://paris/today'),
        );
    }

    public function testCapturedValueIsPercentDecoded(): void
    {
        self::assertSame(['path' => 'hello world'], Matcher::match('file:///{path}', 'file:///hello%20world'));
    }

    public function testRepeatedVariableNameEnforcesIdenticalCaptures(): void
    {
        self::assertSame(['x' => 'foo'], Matcher::match('mirror://{x}/{x}', 'mirror://foo/foo'));
        self::assertNull(Matcher::match('mirror://{x}/{x}', 'mirror://foo/bar'));
    }

    public function testTemplateWithoutVariablesMatchesExactly(): void
    {
        self::assertSame([], Matcher::match('file:///etc', 'file:///etc'));
        self::assertNull(Matcher::match('file:///etc', 'file:///bin'));
    }

    public function testCapturedSegmentDoesNotCrossSlashes(): void
    {
        self::assertNull(Matcher::match('file:///{path}', 'file:///etc/cfg'));
    }

    public function testCapturedSegmentDoesNotCrossQueryOrFragment(): void
    {
        self::assertNull(Matcher::match('file:///{path}', 'file:///etc?q=1'));
        self::assertNull(Matcher::match('file:///{path}', 'file:///etc#frag'));
    }

    public function testDifferentLiteralPrefixDoesNotMatch(): void
    {
        self::assertNull(Matcher::match('file:///{path}', 'http://example.com/etc'));
    }

    public function testLevel2PlusExpressionsAreNotSupported(): void
    {
        self::assertNull(Matcher::match('file:///{+path}', 'file:///etc/cfg'));
        self::assertNull(Matcher::match('weather://{?city}', 'weather://?city=paris'));
        self::assertNull(Matcher::match('file:///{/segments*}', 'file:///a/b/c'));
    }

    public function testCommaSeparatedExpressionsAreNotSupported(): void
    {
        self::assertNull(Matcher::match('weather://{city,day}', 'weather://paris,today'));
    }

    /**
     * @param non-empty-string           $template
     * @param null|array<string, string> $expected
     */
    #[DataProvider('provideRegexSpecialCharactersInLiteralAreEscapedCases')]
    public function testRegexSpecialCharactersInLiteralAreEscaped(string $template, string $uri, ?array $expected): void
    {
        self::assertSame($expected, Matcher::match($template, $uri));
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
}
