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
        self::assertSame(['path' => 'etc'], $this->match('file:///{path}', 'file:///etc'));
    }

    public function testMatchesAVariableNameAtTheValidatorsBound(): void
    {
        $name = str_repeat('a', 32);

        self::assertSame([$name => 'etc'], $this->match(\sprintf('file:///{%s}', $name), 'file:///etc'));
    }

    public function testMatchesMultipleDistinctVariables(): void
    {
        self::assertSame(
            ['city' => 'paris', 'day' => 'today'],
            $this->match('weather://{city}/{day}', 'weather://paris/today'),
        );
    }

    public function testCapturedValueIsPercentDecoded(): void
    {
        self::assertSame(['path' => 'hello world'], $this->match('file:///{path}', 'file:///hello%20world'));
    }

    public function testRepeatedVariableNameEnforcesIdenticalCaptures(): void
    {
        self::assertSame(['x' => 'foo'], $this->match('mirror://{x}/{x}', 'mirror://foo/foo'));
        self::assertNull($this->match('mirror://{x}/{x}', 'mirror://foo/bar'));
    }

    public function testTemplateWithoutVariablesMatchesExactly(): void
    {
        self::assertSame([], $this->match('file:///etc', 'file:///etc'));
        self::assertNull($this->match('file:///etc', 'file:///bin'));
    }

    public function testCapturedSegmentDoesNotCrossSlashes(): void
    {
        self::assertNull($this->match('file:///{path}', 'file:///etc/cfg'));
    }

    public function testCapturedSegmentDoesNotCrossQueryOrFragment(): void
    {
        self::assertNull($this->match('file:///{path}', 'file:///etc?q=1'));
        self::assertNull($this->match('file:///{path}', 'file:///etc#frag'));
    }

    /**
     * @param non-empty-string $template
     */
    #[DataProvider('provideAValueDecodingOutOfItsSegmentDoesNotMatchCases')]
    public function testAValueDecodingOutOfItsSegmentDoesNotMatch(string $template, string $uri): void
    {
        self::assertNull($this->match($template, $uri));
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function provideAValueDecodingOutOfItsSegmentDoesNotMatchCases(): iterable
    {
        yield 'encoded traversal' => ['files://{path}', 'files://%2E%2E%2F%2E%2E%2Fetc%2Fpasswd'];

        yield 'encoded slash' => ['files://{path}', 'files://%2Fetc%2Fshadow'];

        yield 'encoded query delimiter' => ['files://{path}', 'files://%3Fq=1'];

        yield 'encoded fragment delimiter' => ['files://{path}', 'files://%23frag'];

        yield 'encoded NUL byte' => ['files://{path}', 'files://a%00.txt'];

        yield 'bare parent dot-segment' => ['files://{path}', 'files://%2E%2E'];

        yield 'bare current dot-segment' => ['files://{path}', 'files://%2E'];

        yield 'literal parent dot-segment' => ['files://{path}', 'files://..'];

        yield 'a later variable poisons the whole match' => ['files://{area}/{path}', 'files://docs/%2Fetc%2Fshadow'];
    }

    /**
     * @param non-empty-string $template
     */
    #[DataProvider('provideAValueStayingInsideItsSegmentStillMatchesCases')]
    public function testAValueStayingInsideItsSegmentStillMatches(string $template, string $uri, string $expected): void
    {
        self::assertSame(['path' => $expected], $this->match($template, $uri));
    }

    /**
     * @return iterable<string, array{non-empty-string, string, string}>
     */
    public static function provideAValueStayingInsideItsSegmentStillMatchesCases(): iterable
    {
        yield 'a dot inside a name' => ['files://{path}', 'files://report.txt', 'report.txt'];

        yield 'doubled dots inside a name' => ['files://{path}', 'files://a..b', 'a..b'];

        yield 'a leading dot' => ['files://{path}', 'files://.env', '.env'];

        yield 'an encoded space' => ['files://{path}', 'files://my%20report', 'my report'];

        yield 'an encoded percent' => ['files://{path}', 'files://100%25', '100%'];
    }

    public function testDifferentLiteralPrefixDoesNotMatch(): void
    {
        self::assertNull($this->match('file:///{path}', 'http://example.com/etc'));
    }

    public function testLevel2PlusExpressionsAreNotSupported(): void
    {
        self::assertNull($this->match('file:///{+path}', 'file:///etc/cfg'));
        self::assertNull($this->match('weather://{?city}', 'weather://?city=paris'));
        self::assertNull($this->match('file:///{/segments*}', 'file:///a/b/c'));
    }

    public function testCommaSeparatedExpressionsAreNotSupported(): void
    {
        self::assertNull($this->match('weather://{city,day}', 'weather://paris,today'));
    }

    /**
     * @param non-empty-string           $template
     * @param null|array<string, string> $expected
     */
    #[DataProvider('provideRegexSpecialCharactersInLiteralAreEscapedCases')]
    public function testRegexSpecialCharactersInLiteralAreEscaped(string $template, string $uri, ?array $expected): void
    {
        self::assertSame($expected, $this->match($template, $uri));
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
    private function match(string $template, string $uri): ?array
    {
        return Matcher::matchCompiled(Matcher::compile($template), $uri);
    }
}
