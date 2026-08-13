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

use Nexus\Mcp\Core\Validation\Iso8601DateTimeValidator;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(Iso8601DateTimeValidator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class Iso8601DateTimeValidatorTest extends AbstractMcpTestCase
{
    #[DataProvider('provideParseAcceptsValidIso8601Cases')]
    public function testParseAcceptsValidIso8601(string $value, string $expectedFormatted): void
    {
        $parsed = Iso8601DateTimeValidator::parse($value, 'Test field');

        self::assertSame($expectedFormatted, $parsed->format(\DATE_RFC3339));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideParseAcceptsValidIso8601Cases(): iterable
    {
        yield 'plain RFC3339' => ['2026-05-10T12:00:00+00:00', '2026-05-10T12:00:00+00:00'];

        yield 'RFC3339 extended (sub-second)' => ['2026-05-10T12:00:00.123+00:00', '2026-05-10T12:00:00+00:00'];

        yield 'non-UTC offset' => ['2026-05-10T08:00:00-04:00', '2026-05-10T08:00:00-04:00'];

        yield 'lowercase z, case-insensitive per RFC 3339 ABNF' => ['2026-05-10T12:00:00z', '2026-05-10T12:00:00+00:00'];

        yield 'maximal offset' => ['2026-05-10T12:00:00+23:59', '2026-05-10T12:00:00+23:59'];
    }

    #[DataProvider('provideParseRejectsANonRfc3339ShapeCases')]
    public function testParseRejectsANonRfc3339Shape(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Test field must be an RFC 3339 datetime: "YYYY-MM-DDThh:mm:ss", an optional "." fraction, then "Z" or "+hh:mm"/"-hh:mm".');

        Iso8601DateTimeValidator::parse($value, 'Test field');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideParseRejectsANonRfc3339ShapeCases(): iterable
    {
        yield 'UTC' => ['2026-05-10T12:00:00UTC'];

        yield 'GMT behind a fraction' => ['2026-05-10T12:00:00.5GMT'];

        yield 'timezone name' => ['2026-05-10T12:00:00America/New_York'];

        yield 'timezone abbreviation' => ['2026-05-10T12:00:00EST'];

        yield 'space before the offset' => ['2026-05-10T12:00:00 +01:00'];

        yield 'colon-less offset' => ['2026-05-10T12:00:00+0100'];

        yield 'hour-only offset' => ['2026-05-10T12:00:00+01'];

        yield 'offset hour past 23' => ['2026-05-10T12:00:00+24:00'];

        yield 'offset minute past 59' => ['2026-05-10T12:00:00-00:60'];

        yield 'single-digit month' => ['2026-5-10T12:00:00Z'];

        yield 'single-digit day' => ['2026-05-1T12:00:00Z'];

        yield 'single-digit hour' => ['2026-05-10T2:00:00Z'];

        yield 'two-digit year' => ['26-05-10T12:00:00Z'];

        yield 'lowercase t separator, unparsable by the formats behind the gate' => ['2026-05-10t12:00:00Z'];

        yield 'space separator' => ['2026-05-10 12:00:00+00:00'];

        yield 'prefixed garbage' => ['x2026-05-10T12:00:00+00:00'];

        yield 'null byte' => ["2026-05-10T12:00:00\0+00:00"];

        yield 'non-T separator' => ['2026-05-10X12:00:00+00:00'];

        yield 'not a date at all' => ['not-a-date'];

        yield 'missing offset' => ['2026-05-10T12:00:00'];

        yield 'trailing newline' => ["2026-05-10T12:00:00.123+00:00\n"];

        yield 'fractional second with no digits' => ['2026-05-10T12:00:00.+00:00'];
    }

    #[DataProvider('provideParseNormalisesTheFractionalSecondCases')]
    public function testParseNormalisesTheFractionalSecond(string $value, string $expectedMicroseconds): void
    {
        $parsed = Iso8601DateTimeValidator::parse($value, 'Test field');

        self::assertSame($expectedMicroseconds, $parsed->format('u'));
    }

    /**
     * RFC 3339's `time-secfrac` is `"." 1*DIGIT`, so any digit count has to parse.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideParseNormalisesTheFractionalSecondCases(): iterable
    {
        yield 'none' => ['2026-05-10T12:00:00+00:00', '000000'];

        yield 'one digit' => ['2026-05-10T12:00:00.1+00:00', '100000'];

        yield 'two digits' => ['2026-05-10T12:00:00.12+00:00', '120000'];

        yield 'three digits, as JavaScript emits' => ['2026-05-10T12:00:00.123+00:00', '123000'];

        yield 'six digits, as Python emits' => ['2026-05-10T12:00:00.123456+00:00', '123456'];

        yield 'nine digits, truncated to microseconds' => ['2026-05-10T12:00:00.123456789+00:00', '123456'];

        yield 'six digits behind Z' => ['2026-05-10T12:00:00.123456Z', '123456'];

        yield 'one digit behind a non-UTC offset' => ['2026-05-10T08:00:00.5-04:00', '500000'];
    }

    public function testParseRejectsOverflowedDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Test field must be a valid ISO 8601 datetime: The parsed date was invalid.');

        Iso8601DateTimeValidator::parse('2026-13-45T25:99:99+00:00', 'Test field');
    }

    public function testContextAppearsInErrorMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Custom prefix /');

        Iso8601DateTimeValidator::parse('not-a-date', 'Custom prefix');
    }

    #[DataProvider('provideFormatRoundTripsCases')]
    public function testFormatRoundTrips(string $input, string $expectedOutput): void
    {
        $parsed = Iso8601DateTimeValidator::parse($input, 'Test field');

        self::assertSame($expectedOutput, Iso8601DateTimeValidator::format($parsed));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideFormatRoundTripsCases(): iterable
    {
        yield 'plain RFC3339 stays plain' => ['2026-05-10T12:00:00+00:00', '2026-05-10T12:00:00+00:00'];

        yield 'sub-second precision preserved' => ['2026-05-10T12:00:00.123+00:00', '2026-05-10T12:00:00.123000+00:00'];

        yield 'microsecond precision survives the round trip' => ['2026-05-10T12:00:00.123456+00:00', '2026-05-10T12:00:00.123456+00:00'];

        yield 'a fraction with no milliseconds is not flattened away' => ['2026-05-10T12:00:00.000456+00:00', '2026-05-10T12:00:00.000456+00:00'];

        yield 'sub-second .000 collapses to plain' => ['2026-05-10T12:00:00.000+00:00', '2026-05-10T12:00:00+00:00'];

        yield 'non-UTC offset preserved' => ['2026-05-10T08:00:00.500-04:00', '2026-05-10T08:00:00.500000-04:00'];
    }
}
