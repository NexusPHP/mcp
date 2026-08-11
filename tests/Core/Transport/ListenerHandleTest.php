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

namespace Nexus\Mcp\Tests\Core\Transport;

use Nexus\Mcp\Core\Transport\ListenerHandle;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ListenerHandle::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ListenerHandleTest extends AbstractMcpTestCase
{
    public function testDisposeRunsTheCallbackOnce(): void
    {
        $calls = 0;
        $subscription = new ListenerHandle(static function () use (&$calls): void {
            ++$calls;
        });

        $subscription->dispose();

        self::assertSame(1, $calls);
    }

    public function testDisposeIsIdempotent(): void
    {
        $calls = 0;
        $subscription = new ListenerHandle(static function () use (&$calls): void {
            ++$calls;
        });

        $subscription->dispose();
        $subscription->dispose();
        $subscription->dispose();

        self::assertSame(1, $calls);
    }
}
