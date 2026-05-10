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

namespace Nexus\Mcp\Tests\Core\Schema\Enum;

use Nexus\Mcp\Core\Schema\Enum\ToolChoiceMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolChoiceMode::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ToolChoiceModeTest extends TestCase
{
    public function testCases(): void
    {
        self::assertSame(
            ['auto', 'none', 'required'],
            array_map(static fn(ToolChoiceMode $m): string => $m->value, ToolChoiceMode::cases()),
        );
    }

    public function testFromString(): void
    {
        self::assertSame(ToolChoiceMode::Auto, ToolChoiceMode::from('auto'));
        self::assertSame(ToolChoiceMode::None, ToolChoiceMode::from('none'));
        self::assertSame(ToolChoiceMode::Required, ToolChoiceMode::from('required'));
    }
}
