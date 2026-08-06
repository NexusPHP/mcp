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

namespace Nexus\Mcp\Tests\Extension\Tasks\Client\Exception;

use Nexus\Mcp\Extension\Tasks\Client\Exception\StalledTaskException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(StalledTaskException::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class StalledTaskExceptionTest extends AbstractMcpTestCase
{
    public function testCarriesTheTaskAndPollCountInItsMessage(): void
    {
        $exception = new StalledTaskException('task-1', 60);

        self::assertSame('Task "task-1" stayed input_required for 60 polls without new input requests.', $exception->getMessage());
    }
}
