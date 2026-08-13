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

use Nexus\Mcp\Core\Auth\WwwAuthenticateChallenge;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(WwwAuthenticateChallenge::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class WwwAuthenticateChallengeTest extends AbstractMcpTestCase
{
    /**
     * @param list<array{string, array<string, string>}> $expected
     */
    #[DataProvider('provideParseAllCases')]
    public function testParseAll(string $header, array $expected): void
    {
        $actual = array_map(
            static fn(WwwAuthenticateChallenge $challenge): array => [$challenge->scheme, $challenge->parameters],
            WwwAuthenticateChallenge::parseAll($header),
        );

        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{string, list<array{string, array<string, string>}>}>
     */
    public static function provideParseAllCases(): iterable
    {
        yield 'an empty header offers no challenge' => ['', []];

        yield 'a header of only separators offers no challenge' => [',,', []];

        yield 'a header with no token offers no challenge' => ['@@@', []];

        yield 'a segment with no token does not end the scan' => [
            '@@@, Bearer realm="x"',
            [['Bearer', ['realm' => 'x']]],
        ];

        yield 'a leading separator does not disturb the scan' => [
            ',Bearer realm="x"',
            [['Bearer', ['realm' => 'x']]],
        ];

        yield 'a bare scheme parses with no parameters' => ['Bearer', [['Bearer', []]]];

        yield 'a scheme with one parameter' => ['Bearer realm="x"', [['Bearer', ['realm' => 'x']]]];

        yield 'the spec 401 challenge' => [
            'Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource", scope="files:read"',
            [['Bearer', [
                'resource_metadata' => 'https://mcp.example.com/.well-known/oauth-protected-resource',
                'scope' => 'files:read',
            ]]],
        ];

        yield 'the spec 403 insufficient-scope challenge' => [
            'Bearer error="insufficient_scope", scope="files:write", resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource", error_description="File write permission required for this operation"',
            [['Bearer', [
                'error' => 'insufficient_scope',
                'scope' => 'files:write',
                'resource_metadata' => 'https://mcp.example.com/.well-known/oauth-protected-resource',
                'error_description' => 'File write permission required for this operation',
            ]]],
        ];

        yield 'two schemes each keep their own parameters' => [
            'Basic realm="a", Bearer realm="b"',
            [['Basic', ['realm' => 'a']], ['Bearer', ['realm' => 'b']]],
        ];

        yield 'a bare scheme precedes a parameterised one' => [
            'Negotiate, Bearer realm="b"',
            [['Negotiate', []], ['Bearer', ['realm' => 'b']]],
        ];

        yield 'a trailing bare scheme closes the header' => [
            'Bearer realm="a", Basic',
            [['Bearer', ['realm' => 'a']], ['Basic', []]],
        ];

        yield 'a comma inside a quoted value does not split' => [
            'Bearer scope="a,b"',
            [['Bearer', ['scope' => 'a,b']]],
        ];

        yield 'an escaped quote is unescaped into the value' => [
            'Bearer realm="say \"hi\""',
            [['Bearer', ['realm' => 'say "hi"']]],
        ];

        yield 'an escaped backslash is unescaped and does not escape the closing quote' => [
            'Bearer realm="a\\\\", scope="b"',
            [['Bearer', ['realm' => 'a\\', 'scope' => 'b']]],
        ];

        yield 'a backslash outside a quoted value does not escape the separator' => [
            'Bearer realm=a\\, scope=b',
            [['Bearer', ['realm' => 'a', 'scope' => 'b']]],
        ];

        yield 'surrounding spaces are trimmed' => ['  Bearer realm="x"  ', [['Bearer', ['realm' => 'x']]]];

        yield 'a tab separates the scheme from its parameter' => ["Bearer\trealm=\"x\"", [['Bearer', ['realm' => 'x']]]];

        yield 'a tab-padded segment is trimmed' => ["Bearer realm=\"a\",\tscope=\"b\"", [['Bearer', ['realm' => 'a', 'scope' => 'b']]]];

        yield 'whitespace around the equals sign is tolerated' => ['Bearer realm = "x"', [['Bearer', ['realm' => 'x']]]];

        yield 'whitespace around a continuation equals sign is tolerated' => [
            'Bearer realm="a", scope = "b"',
            [['Bearer', ['realm' => 'a', 'scope' => 'b']]],
        ];

        yield 'parameter names are lowercased' => ['Bearer REALM="x"', [['Bearer', ['realm' => 'x']]]];

        yield 'a continuation parameter name is lowercased' => [
            'Bearer realm="a", SCOPE="b"',
            [['Bearer', ['realm' => 'a', 'scope' => 'b']]],
        ];

        yield 'a parameter before any scheme is discarded' => ['realm="x"', []];

        yield 'a token value needs no quotes' => ['Bearer realm=simple', [['Bearer', ['realm' => 'simple']]]];

        yield 'an empty quoted value is kept' => ['Bearer realm=""', [['Bearer', ['realm' => '']]]];

        yield 'a parameter with no value is discarded' => ['Bearer realm=', [['Bearer', []]]];

        yield 'a continuation parameter with no value is discarded' => [
            'Bearer realm="a", scope=',
            [['Bearer', ['realm' => 'a']]],
        ];

        yield 'an unterminated quoted value is discarded' => ['Bearer realm="oops', [['Bearer', []]]];

        yield 'a value ending in a lone backslash is discarded' => ['Bearer realm="x\\', [['Bearer', []]]];

        yield 'a value whose escape consumes the closing quote is discarded' => ['Bearer realm="a\\"', [['Bearer', []]]];

        yield 'a lone quote is not a value' => ['Bearer realm="', [['Bearer', []]]];

        yield 'an unbalanced quote swallows the separator it encloses' => [
            'Bearer realm="oops, scope="b"',
            [['Bearer', ['realm' => 'oops, scope=']]],
        ];

        yield 'a trailing token that is not a parameter is ignored' => ['Bearer opaque', [['Bearer', []]]];
    }

    /**
     * @param null|array{string, array<string, string>} $expected
     */
    #[DataProvider('provideFindBearerCases')]
    public function testFindBearer(string $header, ?array $expected): void
    {
        $challenge = WwwAuthenticateChallenge::findBearer($header);

        if (null === $expected) {
            self::assertNull($challenge);

            return;
        }

        self::assertInstanceOf(WwwAuthenticateChallenge::class, $challenge);

        self::assertSame($expected, [$challenge->scheme, $challenge->parameters]);
    }

    /**
     * @return iterable<string, array{string, null|array{string, array<string, string>}}>
     */
    public static function provideFindBearerCases(): iterable
    {
        yield 'an empty header offers no Bearer challenge' => ['', null];

        yield 'a non-Bearer scheme is not matched' => ['Basic realm="x"', null];

        yield 'the Bearer challenge is returned' => ['Bearer realm="x"', ['Bearer', ['realm' => 'x']]];

        yield 'the scheme is matched case-insensitively' => ['bearer realm="x"', ['bearer', ['realm' => 'x']]];

        yield 'the Bearer challenge is picked out of several' => [
            'Basic realm="a", Bearer realm="b"',
            ['Bearer', ['realm' => 'b']],
        ];
    }

    public function testParseAllStripsControlOctetsFromAQuotedValue(): void
    {
        $challenges = WwwAuthenticateChallenge::parseAll(
            "Bearer scope=\"mcp:use \x1b[2K\x1b[1GFORGED-ADMIN-GRANT\x07\", realm=\"a\rb\nc\x7Fd\"",
        );

        self::assertCount(1, $challenges);
        self::assertSame('mcp:use [2K[1GFORGED-ADMIN-GRANT', $challenges[0]->readParameter('scope'));
        self::assertSame('abcd', $challenges[0]->readParameter('realm'));
    }

    public function testParseAllStripsAControlOctetSmuggledThroughAQuotedPair(): void
    {
        $challenges = WwwAuthenticateChallenge::parseAll("Bearer realm=\"x\\\x1by\"");

        self::assertCount(1, $challenges);
        self::assertSame('xy', $challenges[0]->readParameter('realm'));
    }

    public function testParseAllKeepsTabsAndNonAsciiInAQuotedValue(): void
    {
        $challenges = WwwAuthenticateChallenge::parseAll("Bearer realm=\"gr\xC3\xBCn\tbar\"");

        self::assertCount(1, $challenges);
        self::assertSame("grün\tbar", $challenges[0]->readParameter('realm'));
    }

    public function testReadParameterMatchesCaseInsensitively(): void
    {
        $challenge = new WwwAuthenticateChallenge('Bearer', ['scope' => 'files:read']);

        self::assertSame('files:read', $challenge->readParameter('scope'));
        self::assertSame('files:read', $challenge->readParameter('SCOPE'));
    }

    public function testReadParameterReturnsNullForAnAbsentParameter(): void
    {
        self::assertNull((new WwwAuthenticateChallenge('Bearer'))->readParameter('scope'));
    }

    public function testConstructorLowercasesParameterNames(): void
    {
        self::assertSame(['realm' => 'x'], (new WwwAuthenticateChallenge('Bearer', ['REALM' => 'x']))->parameters);
    }

    /**
     * @param non-empty-string                $scheme
     * @param array<non-empty-string, string> $parameters
     */
    #[DataProvider('provideToHeaderValueCases')]
    public function testToHeaderValue(string $scheme, array $parameters, string $expected): void
    {
        self::assertSame($expected, (new WwwAuthenticateChallenge($scheme, $parameters))->toHeaderValue());
    }

    /**
     * @return iterable<string, array{non-empty-string, array<non-empty-string, string>, string}>
     */
    public static function provideToHeaderValueCases(): iterable
    {
        yield 'a bare scheme renders alone' => ['Bearer', [], 'Bearer'];

        yield 'one parameter is quoted' => ['Bearer', ['realm' => 'x'], 'Bearer realm="x"'];

        yield 'several parameters are comma-separated' => [
            'Bearer',
            ['resource_metadata' => 'https://mcp.example.com/.well-known/oauth-protected-resource', 'scope' => 'files:read'],
            'Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource", scope="files:read"',
        ];

        yield 'a quote in a value is escaped' => ['Bearer', ['realm' => 'say "hi"'], 'Bearer realm="say \"hi\""'];

        yield 'a backslash in a value is escaped' => ['Bearer', ['realm' => 'a\\b'], 'Bearer realm="a\\\\b"'];
    }

    public function testToHeaderValueRoundTripsThroughParseAll(): void
    {
        $challenge = new WwwAuthenticateChallenge('Bearer', [
            'resource_metadata' => 'https://mcp.example.com/.well-known/oauth-protected-resource',
            'scope' => 'files:read files:write',
            'error_description' => 'quotes " and \\ backslashes',
        ]);

        $parsed = WwwAuthenticateChallenge::findBearer($challenge->toHeaderValue());

        self::assertInstanceOf(WwwAuthenticateChallenge::class, $parsed);

        self::assertSame($challenge->parameters, $parsed->parameters);
    }
}
