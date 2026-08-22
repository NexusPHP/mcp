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

use Nexus\Mcp\Extension\Tasks\Server\TaskCancellationRegistry;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(TaskCancellationRegistry::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class TaskCancellationRegistryTest extends AbstractMcpTestCase
{
    public function testCancelRequestsTheRegisteredToken(): void
    {
        $registry = new TaskCancellationRegistry();
        $token = $registry->register('task-1');

        self::assertFalse($token->isRequested());

        $registry->cancel('task-1');

        self::assertTrue($token->isRequested());
    }

    public function testCancelOfAnUnknownTaskIsANoOp(): void
    {
        $this->expectNotToPerformAssertions();

        (new TaskCancellationRegistry())->cancel('missing');
    }

    public function testCountFollowsRegisterAndRelease(): void
    {
        $registry = new TaskCancellationRegistry();

        self::assertCount(0, $registry);

        $registry->register('task-1');
        $registry->register('task-2');

        self::assertCount(2, $registry);

        $registry->release('task-1');

        self::assertCount(1, $registry);
    }

    public function testReleaseDropsTheSource(): void
    {
        $registry = new TaskCancellationRegistry();
        $registry->register('task-1');
        $registry->release('task-1');

        $sources = (new \ReflectionProperty(TaskCancellationRegistry::class, 'sources'))->getValue($registry);
        self::assertSame([], $sources);
    }

    public function testRegisterReplacesAPriorSource(): void
    {
        $registry = new TaskCancellationRegistry();
        $registry->register('task-1');
        $second = $registry->register('task-1');

        $registry->cancel('task-1');

        self::assertTrue($second->isRequested());
    }
}
