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

namespace Nexus\Mcp\Tests\Extension\Tasks\Server;

use Nexus\Mcp\Extension\Tasks\Server\TaskSupport;
use Nexus\Mcp\Extension\Tasks\Server\ToolTaskPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolTaskPolicy::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ToolTaskPolicyTest extends TestCase
{
    public function testDefaultsToNotResolvingInputFirst(): void
    {
        $policy = new ToolTaskPolicy(support: TaskSupport::Optional);

        self::assertSame(TaskSupport::Optional, $policy->support);
        self::assertFalse($policy->resolvesInputFirst);
    }

    public function testCarriesTheResolvesInputFirstFlag(): void
    {
        $policy = new ToolTaskPolicy(support: TaskSupport::Required, resolvesInputFirst: true);

        self::assertSame(TaskSupport::Required, $policy->support);
        self::assertTrue($policy->resolvesInputFirst);
    }
}
