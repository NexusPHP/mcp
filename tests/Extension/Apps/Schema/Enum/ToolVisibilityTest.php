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

namespace Nexus\Mcp\Tests\Extension\Apps\Schema\Enum;

use Nexus\Mcp\Extension\Apps\Schema\Enum\ToolVisibility;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ToolVisibility::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ToolVisibilityTest extends AbstractMcpTestCase
{
    #[DataProvider('provideToolVisibilityCaseValueCases')]
    public function testToolVisibilityCaseValue(ToolVisibility $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{ToolVisibility, string}>
     */
    public static function provideToolVisibilityCaseValueCases(): iterable
    {
        yield 'Model' => [ToolVisibility::Model, 'model'];

        yield 'App' => [ToolVisibility::App, 'app'];
    }

    public function testPinsTheSpecCaseSet(): void
    {
        self::assertSame(['model', 'app'], array_column(ToolVisibility::cases(), 'value'));
    }
}
