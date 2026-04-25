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

namespace Nexus\Mcp\Tests\Core\JsonRpc;

use Nexus\Mcp\Core\JsonRpc\JsonRpcMethodRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(JsonRpcMethodRegistry::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class JsonRpcMethodRegistryTest extends TestCase
{
    public function testRequestsBindEachMethodLiteralToItsClass(): void
    {
        $registry = JsonRpcMethodRegistry::requests();
        self::assertNotEmpty($registry);

        foreach ($registry as $method => $class) {
            self::assertSame($method, $class::method(), \sprintf(
                'Registry key "%s" must match %s::method().',
                $method,
                $class,
            ));
        }
    }

    public function testNotificationsBindEachMethodLiteralToItsClass(): void
    {
        $registry = JsonRpcMethodRegistry::notifications();
        self::assertNotEmpty($registry);

        foreach ($registry as $method => $class) {
            self::assertSame($method, $class::method(), \sprintf(
                'Registry key "%s" must match %s::method().',
                $method,
                $class,
            ));
        }
    }
}
