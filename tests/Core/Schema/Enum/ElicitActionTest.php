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

use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ElicitAction::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ElicitActionTest extends TestCase
{
    #[DataProvider('provideElicitActionCaseValueCases')]
    public function testElicitActionCaseValue(ElicitAction $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{ElicitAction, string}>
     */
    public static function provideElicitActionCaseValueCases(): iterable
    {
        yield 'Accept' => [ElicitAction::Accept, 'accept'];

        yield 'Decline' => [ElicitAction::Decline, 'decline'];

        yield 'Cancel' => [ElicitAction::Cancel, 'cancel'];
    }
}
