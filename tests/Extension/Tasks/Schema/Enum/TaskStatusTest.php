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

namespace Nexus\Mcp\Tests\Extension\Tasks\Schema\Enum;

use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(TaskStatus::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class TaskStatusTest extends AbstractMcpTestCase
{
    #[DataProvider('provideTaskStatusCaseValueCases')]
    public function testTaskStatusCaseValue(TaskStatus $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{TaskStatus, string}>
     */
    public static function provideTaskStatusCaseValueCases(): iterable
    {
        yield 'Working' => [TaskStatus::Working, 'working'];

        yield 'InputRequired' => [TaskStatus::InputRequired, 'input_required'];

        yield 'Completed' => [TaskStatus::Completed, 'completed'];

        yield 'Cancelled' => [TaskStatus::Cancelled, 'cancelled'];

        yield 'Failed' => [TaskStatus::Failed, 'failed'];
    }
}
