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

namespace Nexus\Mcp\Tests\Core;

use Nexus\Mcp\Core\SafeDisplay;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SafeDisplay::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SafeDisplayTest extends AbstractMcpTestCase
{
    #[DataProvider('provideSanitiseCases')]
    public function testSanitise(string $input, string $expected): void
    {
        self::assertSame($expected, SafeDisplay::sanitise($input));
    }

    /**
     * @return iterable<string, array{input: string, expected: string}>
     */
    public static function provideSanitiseCases(): iterable
    {
        yield 'printable ASCII passes through unchanged' => [
            'input' => 'tools/list',
            'expected' => 'tools/list',
        ];

        yield 'newline is hex-escaped' => [
            'input' => "tools/list\n",
            'expected' => 'tools/list\\x0a',
        ];

        yield 'tab is hex-escaped' => [
            'input' => "tools\tlist",
            'expected' => 'tools\\x09list',
        ];

        yield 'ANSI escape is hex-escaped' => [
            'input' => "\x1b[31mtools/list\x1b[0m",
            'expected' => '\\x1b[31mtools/list\\x1b[0m',
        ];

        yield 'the byte below the printable floor is hex-escaped' => [
            'input' => "a\x1fb",
            'expected' => 'a\\x1fb',
        ];

        yield 'the space at the printable floor passes through' => [
            'input' => 'a b',
            'expected' => 'a b',
        ];

        yield 'the tilde at the printable ceiling passes through' => [
            'input' => 'a~b',
            'expected' => 'a~b',
        ];

        yield 'the delete byte above the printable ceiling is hex-escaped' => [
            'input' => "a\x7fb",
            'expected' => 'a\\x7fb',
        ];

        yield 'RTL override is hex-escaped (UTF-8 three-byte sequence)' => [
            'input' => "tools\u{202E}list",
            'expected' => 'tools\\xe2\\x80\\xaelist',
        ];

        yield 'NUL byte is hex-escaped' => [
            'input' => "tools\0list",
            'expected' => 'tools\\x00list',
        ];

        yield 'empty string stays empty' => [
            'input' => '',
            'expected' => '',
        ];

        yield 'string at exactly the 80-byte cap is returned unchanged' => [
            'input' => str_repeat('a', 80),
            'expected' => str_repeat('a', 80),
        ];

        yield 'string one byte beyond the cap is truncated with ellipsis' => [
            'input' => str_repeat('a', 81),
            'expected' => str_repeat('a', 77).'...',
        ];

        yield 'string exceeding the 80-byte cap is truncated with ellipsis' => [
            'input' => str_repeat('a', 100),
            'expected' => str_repeat('a', 77).'...',
        ];

        yield 'hex-escape expansion that pushes past the cap is truncated' => [
            'input' => str_repeat("\n", 30),
            'expected' => str_repeat('\\x0a', 19).'\\...',
        ];
    }

    #[DataProvider('provideSanitiseCauseCases')]
    public function testSanitiseCause(string $input, string $expected): void
    {
        self::assertSame($expected, SafeDisplay::sanitiseCause($input));
    }

    /**
     * @return iterable<string, array{input: string, expected: string}>
     */
    public static function provideSanitiseCauseCases(): iterable
    {
        yield 'a message longer than the value cap survives whole' => [
            'input' => str_repeat('a', 200),
            'expected' => str_repeat('a', 200),
        ];

        yield 'a message at exactly the 256-byte cap is returned unchanged' => [
            'input' => str_repeat('a', 256),
            'expected' => str_repeat('a', 256),
        ];

        yield 'a message one byte beyond the cap is truncated with ellipsis' => [
            'input' => str_repeat('a', 257),
            'expected' => str_repeat('a', 253).'...',
        ];

        yield 'ANSI escape is hex-escaped' => [
            'input' => "cause\x1b[2Kforged\x07",
            'expected' => 'cause\\x1b[2Kforged\\x07',
        ];
    }

    #[DataProvider('provideSanitiseIdCases')]
    public function testSanitiseId(int|string $input, int|string $expected): void
    {
        self::assertSame($expected, SafeDisplay::sanitiseId($input));
    }

    /**
     * @return iterable<string, array{input: int|string, expected: int|string}>
     */
    public static function provideSanitiseIdCases(): iterable
    {
        yield 'an int id stays an int' => [
            'input' => 42,
            'expected' => 42,
        ];

        yield 'a negative int id stays an int' => [
            'input' => -1,
            'expected' => -1,
        ];

        yield 'a string id is escaped' => [
            'input' => "req\x1b]0;forged\x07",
            'expected' => 'req\\x1b]0;forged\\x07',
        ];

        yield 'a string id is capped at the value cap' => [
            'input' => str_repeat('r', 200),
            'expected' => str_repeat('r', 77).'...',
        ];
    }
}
