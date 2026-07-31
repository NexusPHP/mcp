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

namespace Nexus\Mcp\Tests\Core\Exception;

use Nexus\Mcp\Core\Exception\SupervisionExhaustedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SupervisionExhaustedException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SupervisionExhaustedExceptionTest extends TestCase
{
    public function testRendersTheSpentBudgetIntoMessage(): void
    {
        $e = new SupervisionExhaustedException(3);

        self::assertSame('Gave up supervising the peer after 3 restart attempt(s) without a served message.', $e->getMessage());
        self::assertSame(3, $e->restarts);
        self::assertNull($e->getPrevious());
    }

    public function testCarriesThePreviousThrowable(): void
    {
        $previous = new \RuntimeException('spawn failed');
        $e = new SupervisionExhaustedException(1, $previous);

        self::assertSame($previous, $e->getPrevious());
    }
}
