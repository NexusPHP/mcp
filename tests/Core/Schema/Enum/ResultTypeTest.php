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

use Nexus\Mcp\Core\Schema\Enum\ResultType;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ResultType::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ResultTypeTest extends AbstractMcpTestCase
{
    #[DataProvider('provideResultTypeCaseValueCases')]
    public function testResultTypeCaseValue(ResultType $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{ResultType, string}>
     */
    public static function provideResultTypeCaseValueCases(): iterable
    {
        yield 'Complete' => [ResultType::Complete, 'complete'];

        yield 'InputRequired' => [ResultType::InputRequired, 'input_required'];

        yield 'Task' => [ResultType::Task, 'task'];
    }
}
