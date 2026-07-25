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

namespace Nexus\Mcp\Tests\Core\Http;

use Nexus\Mcp\Core\Http\SseFrame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SseFrame::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class SseFrameTest extends TestCase
{
    public function testExposesItsFields(): void
    {
        $frame = new SseFrame('message', '{"jsonrpc":"2.0"}');

        self::assertSame('message', $frame->event);
        self::assertSame('{"jsonrpc":"2.0"}', $frame->data);
    }
}
