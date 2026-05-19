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

namespace Nexus\Mcp\Tests\Core\JsonRpc;

use Nexus\Mcp\Core\JsonRpc\SafeDisplay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SafeDisplay::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SafeDisplayTest extends TestCase
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
}
