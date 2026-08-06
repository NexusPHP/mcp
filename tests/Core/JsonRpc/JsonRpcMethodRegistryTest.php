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
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(JsonRpcMethodRegistry::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class JsonRpcMethodRegistryTest extends AbstractMcpTestCase
{
    public function testRequestsBindEachMethodLiteralToItsClass(): void
    {
        $registry = JsonRpcMethodRegistry::requests();
        self::assertNotEmpty($registry);

        foreach ($registry as $method => $class) {
            self::assertSame($method, $class::getMethod(), \sprintf(
                'Registry key "%s" must match %s::getMethod().',
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
            self::assertSame($method, $class::getMethod(), \sprintf(
                'Registry key "%s" must match %s::getMethod().',
                $method,
                $class,
            ));
        }
    }

    public function testRequestsAreSortedByEvaluatedMethodKey(): void
    {
        self::assertRegistryIsSortedByKey(JsonRpcMethodRegistry::requests(), 'requests');
    }

    public function testNotificationsAreSortedByEvaluatedMethodKey(): void
    {
        self::assertRegistryIsSortedByKey(JsonRpcMethodRegistry::notifications(), 'notifications');
    }

    public function testEveryConcreteJsonRpcRequestClassIsRegistered(): void
    {
        $registry = JsonRpcMethodRegistry::requests();

        foreach (self::concreteSubclassesUnder(__DIR__.'/../../../src/Core/Schema/Request', 'Nexus\\Mcp\\Core\\Schema\\Request', JsonRpcRequest::class) as $class) {
            $method = $class::getMethod();
            self::assertArrayHasKey($method, $registry, \sprintf('Concrete request class "%s" (method "%s") must be registered.', $class, $method));
            self::assertSame($class, $registry[$method], \sprintf('Method "%s" must map to "%s".', $method, $class));
        }
    }

    public function testEveryConcreteJsonRpcNotificationClassIsRegistered(): void
    {
        $registry = JsonRpcMethodRegistry::notifications();

        foreach (self::concreteSubclassesUnder(__DIR__.'/../../../src/Core/Schema/Notification', 'Nexus\\Mcp\\Core\\Schema\\Notification', JsonRpcNotification::class) as $class) {
            $method = $class::getMethod();
            self::assertArrayHasKey($method, $registry, \sprintf('Concrete notification class "%s" (method "%s") must be registered.', $class, $method));
            self::assertSame($class, $registry[$method], \sprintf('Method "%s" must map to "%s".', $method, $class));
        }
    }

    /**
     * Verifies the registry's iteration order matches `sort()` on its keys.
     * The order itself is human-meaningful (entries are easier to find when
     * grouped by method-name prefix), so a regression (e.g. appending a
     * new entry at the bottom instead of at its sorted position) should
     * fail the build rather than silently drift.
     *
     * @param array<non-empty-string, class-string> $registry
     */
    private static function assertRegistryIsSortedByKey(array $registry, string $label): void
    {
        $keys = array_keys($registry);
        $sorted = $keys;
        sort($sorted);

        self::assertSame($sorted, $keys, \sprintf(
            'JsonRpcMethodRegistry::%s() entries must be sorted by evaluated method key. Expected order: %s.',
            $label,
            implode(', ', $sorted),
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $parentClass
     *
     * @return iterable<class-string<T>>
     */
    private static function concreteSubclassesUnder(string $directory, string $namespace, string $parentClass): iterable
    {
        $files = glob($directory.'/*.php');
        self::assertIsArray($files, \sprintf('Failed to list files under "%s".', $directory));

        foreach ($files as $file) {
            $class = $namespace.'\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, $parentClass)) {
                continue;
            }

            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            yield $class;
        }
    }
}
