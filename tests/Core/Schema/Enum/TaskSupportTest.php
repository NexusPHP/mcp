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

use Nexus\Mcp\Core\Schema\Enum\TaskSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(TaskSupport::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class TaskSupportTest extends TestCase
{
    #[DataProvider('provideTaskSupportCaseValueCases')]
    public function testTaskSupportCaseValue(TaskSupport $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{TaskSupport, string}>
     */
    public static function provideTaskSupportCaseValueCases(): iterable
    {
        yield 'Forbidden' => [TaskSupport::Forbidden, 'forbidden'];

        yield 'Optional' => [TaskSupport::Optional, 'optional'];

        yield 'Required' => [TaskSupport::Required, 'required'];
    }
}
