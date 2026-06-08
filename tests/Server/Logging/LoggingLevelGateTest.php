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

namespace Nexus\Mcp\Tests\Server\Logging;

use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Server\Logging\LoggingLevelGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(LoggingLevelGate::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class LoggingLevelGateTest extends TestCase
{
    public function testDefaultLevelIsInfo(): void
    {
        $gate = new LoggingLevelGate();

        self::assertSame(LoggingLevel::Info, $gate->level);
    }

    public function testConstructorAcceptsAnExplicitLevel(): void
    {
        $gate = new LoggingLevelGate(LoggingLevel::Debug);

        self::assertSame(LoggingLevel::Debug, $gate->level);
    }

    /**
     * @param non-empty-string $why
     */
    #[DataProvider('provideShouldEmitMatrixCases')]
    public function testShouldEmitMatrix(LoggingLevel $threshold, LoggingLevel $message, bool $expected, string $why): void
    {
        $gate = new LoggingLevelGate($threshold);

        self::assertSame($expected, $gate->shouldEmit($message), $why);
    }

    /**
     * For each adjacent (more-severe, less-severe) pair in the RFC 5424 ladder
     * this provider asserts the three boundary outcomes:
     *   - threshold = severe, message = less severe → drop
     *   - threshold = less severe, message = severe → emit
     *   - threshold = X, message = X → emit
     * Plus the two endpoints (Emergency = nothing-but-itself, Debug = admits all)
     * to pin the ends of the ladder.
     *
     * @return iterable<string, array{LoggingLevel, LoggingLevel, bool, non-empty-string}>
     */
    public static function provideShouldEmitMatrixCases(): iterable
    {
        // Endpoints.
        yield 'debug threshold accepts emergency (Debug admits all)' => [
            LoggingLevel::Debug,
            LoggingLevel::Emergency,
            true,
            'emergency is more severe than debug',
        ];

        yield 'emergency threshold drops debug (Emergency admits only itself)' => [
            LoggingLevel::Emergency,
            LoggingLevel::Debug,
            false,
            'debug is the least severe',
        ];

        // Adjacent pairs, ordered most-severe → least-severe.
        $pairs = [
            'emergency-alert' => [LoggingLevel::Emergency, LoggingLevel::Alert],
            'alert-critical' => [LoggingLevel::Alert, LoggingLevel::Critical],
            'critical-error' => [LoggingLevel::Critical, LoggingLevel::Error],
            'error-warning' => [LoggingLevel::Error, LoggingLevel::Warning],
            'warning-notice' => [LoggingLevel::Warning, LoggingLevel::Notice],
            'notice-info' => [LoggingLevel::Notice, LoggingLevel::Info],
            'info-debug' => [LoggingLevel::Info, LoggingLevel::Debug],
        ];

        foreach ($pairs as $label => [$severe, $lessSevere]) {
            yield \sprintf('%s: %s threshold drops %s', $label, $severe->value, $lessSevere->value) => [
                $severe,
                $lessSevere,
                false,
                \sprintf('%s is less severe than %s', $lessSevere->value, $severe->value),
            ];

            yield \sprintf('%s: %s threshold accepts %s', $label, $lessSevere->value, $severe->value) => [
                $lessSevere,
                $severe,
                true,
                \sprintf('%s is more severe than %s', $severe->value, $lessSevere->value),
            ];

            yield \sprintf('%s: %s threshold accepts itself', $label, $severe->value) => [
                $severe,
                $severe,
                true,
                \sprintf('%s == %s', $severe->value, $severe->value),
            ];
        }

        // Debug == Debug (the only level not covered as a "severe" endpoint above).
        yield 'debug threshold accepts debug' => [
            LoggingLevel::Debug,
            LoggingLevel::Debug,
            true,
            'debug == debug',
        ];
    }
}
