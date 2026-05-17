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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Iso8601DateTimeValidator::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class Iso8601DateTimeValidatorTest extends TestCase
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
    }

    public function testParseRejectsNullByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Test field must not contain NULL bytes.');

        Iso8601DateTimeValidator::parse("2026-05-10T12:00:00\0+00:00", 'Test field');
    }

    public function testParseRejectsInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Test field must be a valid ISO 8601 datetime.');

        Iso8601DateTimeValidator::parse('not-a-date', 'Test field');
    }

    public function testParseRejectsMissingTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Test field must be a valid ISO 8601 datetime.');

        Iso8601DateTimeValidator::parse('2026-05-10T12:00:00', 'Test field');
    }

    public function testParseRejectsOverflowedDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The parsed date was invalid.');

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

        yield 'sub-second precision preserved' => ['2026-05-10T12:00:00.123+00:00', '2026-05-10T12:00:00.123+00:00'];

        yield 'sub-second .000 collapses to plain' => ['2026-05-10T12:00:00.000+00:00', '2026-05-10T12:00:00+00:00'];

        yield 'non-UTC offset preserved' => ['2026-05-10T08:00:00.500-04:00', '2026-05-10T08:00:00.500-04:00'];
    }
}
