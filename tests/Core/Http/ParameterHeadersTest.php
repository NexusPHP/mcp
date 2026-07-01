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

namespace Nexus\Mcp\Tests\Core\Http;

use Nexus\Mcp\Core\Http\HeaderValueCodec;
use Nexus\Mcp\Core\Http\ParameterHeaderBinding;
use Nexus\Mcp\Core\Http\ParameterHeaders;
use Nexus\Mcp\Core\Schema\Error\HeaderMismatchError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ParameterHeaders::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ParameterHeadersTest extends TestCase
{
    /**
     * @param list<ParameterHeaderBinding> $bindings
     * @param array<string, mixed>         $arguments
     * @param array<string, string>        $expected
     */
    #[DataProvider('provideBuildCases')]
    public function testBuild(array $bindings, array $arguments, array $expected): void
    {
        self::assertSame($expected, ParameterHeaders::build($bindings, $arguments));
    }

    /**
     * @return iterable<string, array{list<ParameterHeaderBinding>, array<string, mixed>, array<string, string>}>
     */
    public static function provideBuildCases(): iterable
    {
        $region = new ParameterHeaderBinding(['region'], 'Region', 'string');

        yield 'string argument' => [[$region], ['region' => 'us-west1'], ['Mcp-Param-Region' => 'us-west1']];

        yield 'integer argument' => [
            [new ParameterHeaderBinding(['count'], 'Count', 'integer')],
            ['count' => 42],
            ['Mcp-Param-Count' => '42'],
        ];

        yield 'boolean true argument' => [
            [new ParameterHeaderBinding(['flag'], 'Flag', 'boolean')],
            ['flag' => true],
            ['Mcp-Param-Flag' => 'true'],
        ];

        yield 'boolean false argument' => [
            [new ParameterHeaderBinding(['flag'], 'Flag', 'boolean')],
            ['flag' => false],
            ['Mcp-Param-Flag' => 'false'],
        ];

        yield 'nested path argument' => [
            [new ParameterHeaderBinding(['a', 'b'], 'B', 'string')],
            ['a' => ['b' => 'x']],
            ['Mcp-Param-B' => 'x'],
        ];

        yield 'null argument is omitted' => [[$region], ['region' => null], []];

        yield 'absent argument is omitted' => [[$region], [], []];

        yield 'non-primitive argument is omitted' => [[$region], ['region' => ['a', 'b']], []];

        yield 'integer beyond the safe range is omitted' => [
            [new ParameterHeaderBinding(['count'], 'Count', 'integer')],
            ['count' => 9_007_199_254_740_992],
            [],
        ];

        yield 'integer at the safe maximum' => [
            [new ParameterHeaderBinding(['count'], 'Count', 'integer')],
            ['count' => 9_007_199_254_740_991],
            ['Mcp-Param-Count' => '9007199254740991'],
        ];

        yield 'integer at the safe minimum' => [
            [new ParameterHeaderBinding(['count'], 'Count', 'integer')],
            ['count' => -9_007_199_254_740_991],
            ['Mcp-Param-Count' => '-9007199254740991'],
        ];

        yield 'multiple bindings' => [
            [$region, new ParameterHeaderBinding(['zone'], 'Zone', 'string')],
            ['region' => 'us-west1', 'zone' => 'a'],
            ['Mcp-Param-Region' => 'us-west1', 'Mcp-Param-Zone' => 'a'],
        ];

        yield 'an omitted binding does not stop a later one' => [
            [$region, new ParameterHeaderBinding(['zone'], 'Zone', 'string')],
            ['zone' => 'a'],
            ['Mcp-Param-Zone' => 'a'],
        ];

        yield 'no bindings' => [[], ['region' => 'us-west1'], []];
    }

    public function testBuildEncodesNonAsciiValues(): void
    {
        $headers = ParameterHeaders::build(
            [new ParameterHeaderBinding(['region'], 'Region', 'string')],
            ['region' => 'wörld'],
        );

        self::assertArrayHasKey('Mcp-Param-Region', $headers);
        self::assertStringStartsWith('=?base64?', $headers['Mcp-Param-Region']);
        self::assertSame('wörld', HeaderValueCodec::decode($headers['Mcp-Param-Region']));
    }

    /**
     * @param list<ParameterHeaderBinding> $bindings
     * @param array<string, mixed>         $arguments
     * @param array<string, string>        $headers
     */
    #[DataProvider('provideValidateAcceptsCases')]
    public function testValidateAccepts(array $bindings, array $arguments, array $headers): void
    {
        self::assertNull(ParameterHeaders::validate($bindings, $arguments, $headers));
    }

    /**
     * @return iterable<string, array{list<ParameterHeaderBinding>, array<string, mixed>, array<string, string>}>
     */
    public static function provideValidateAcceptsCases(): iterable
    {
        $region = new ParameterHeaderBinding(['region'], 'Region', 'string');
        $count = new ParameterHeaderBinding(['count'], 'Count', 'integer');

        yield 'matching string value' => [[$region], ['region' => 'us-west1'], ['mcp-param-region' => 'us-west1']];

        yield 'header name matched case-insensitively' => [[$region], ['region' => 'us-west1'], ['Mcp-Param-Region' => 'us-west1']];

        yield 'matching encoded value' => [
            [$region],
            ['region' => 'wörld'],
            ['mcp-param-region' => HeaderValueCodec::encode('wörld')],
        ];

        yield 'null argument expects no header' => [[$region], ['region' => null], []];

        yield 'absent argument expects no header' => [[$region], [], []];

        yield 'non-primitive argument expects no header' => [[$region], ['region' => ['a']], []];

        yield 'integer matched as a string' => [[$count], ['count' => 42], ['mcp-param-count' => '42']];

        yield 'integer matched numerically against a decimal header' => [[$count], ['count' => 42], ['mcp-param-count' => '42.0']];
    }

    /**
     * @param list<ParameterHeaderBinding> $bindings
     * @param array<string, mixed>         $arguments
     * @param array<string, string>        $headers
     */
    #[DataProvider('provideValidateRejectsCases')]
    public function testValidateRejects(array $bindings, array $arguments, array $headers): void
    {
        self::assertInstanceOf(HeaderMismatchError::class, ParameterHeaders::validate($bindings, $arguments, $headers));
    }

    /**
     * @return iterable<string, array{list<ParameterHeaderBinding>, array<string, mixed>, array<string, string>}>
     */
    public static function provideValidateRejectsCases(): iterable
    {
        $region = new ParameterHeaderBinding(['region'], 'Region', 'string');
        $count = new ParameterHeaderBinding(['count'], 'Count', 'integer');

        yield 'header absent while the body carries the argument' => [[$region], ['region' => 'us-west1'], []];

        yield 'header carries an invalid encoded value' => [
            [$region],
            ['region' => 'us-west1'],
            ['mcp-param-region' => '=?base64?not*base64?='],
        ];

        yield 'header value does not match the body' => [
            [$region],
            ['region' => 'us-west1'],
            ['mcp-param-region' => 'us-east1'],
        ];

        yield 'integer header does not match numerically' => [[$count], ['count' => 42], ['mcp-param-count' => '43']];

        yield 'integer header in a non-decimal form falls back to a string mismatch' => [
            [$count],
            ['count' => 42],
            ['mcp-param-count' => '0x2a'],
        ];

        yield 'one of several bindings mismatches' => [
            [$region, $count],
            ['region' => 'us-west1', 'count' => 42],
            ['mcp-param-region' => 'us-west1', 'mcp-param-count' => '99'],
        ];

        yield 'a skipped binding does not stop a later mismatch' => [
            [$region, $count],
            ['count' => 42],
            ['mcp-param-count' => '99'],
        ];
    }
}
