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

namespace Nexus\Mcp\Tests\Core\Handler;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\Handler\NotificationHandlerRegistry;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingNotificationHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(NotificationHandlerRegistry::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class NotificationHandlerRegistryTest extends TestCase
{
    public function testHasReportsRegisteredAndUnregisteredMethods(): void
    {
        $registry = new NotificationHandlerRegistry([
            InitializedNotification::method() => new RecordingNotificationHandler(),
        ]);

        self::assertTrue($registry->has(InitializedNotification::method()));
        self::assertFalse($registry->has('vendor/unknown'));
    }

    public function testGetReturnsRegisteredHandler(): void
    {
        $handler = new RecordingNotificationHandler();
        $registry = new NotificationHandlerRegistry([
            InitializedNotification::method() => $handler,
        ]);

        self::assertSame($handler, $registry->get(InitializedNotification::method()));
    }

    public function testGetThrowsMethodNotFoundExceptionForUnregisteredMethod(): void
    {
        $this->expectException(MethodNotFoundException::class);
        $this->expectExceptionMessage('No registration found for method "vendor/unknown".');

        new NotificationHandlerRegistry([])->get('vendor/unknown');
    }

    public function testMethodsReturnsRegisteredKeysInInsertionOrder(): void
    {
        $registry = new NotificationHandlerRegistry([
            'b/method' => new RecordingNotificationHandler(),
            'a/method' => new RecordingNotificationHandler(),
        ]);

        self::assertSame(['b/method', 'a/method'], $registry->methods());
    }

    public function testMethodsReturnsEmptyListForEmptyRegistry(): void
    {
        $registry = new NotificationHandlerRegistry([]);

        self::assertSame([], $registry->methods());
    }

    public function testConstructorRejectsEmptyStringKey(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Notification handler registry key must be a non-empty string.');

        // @phpstan-ignore argument.type
        new NotificationHandlerRegistry(['' => new RecordingNotificationHandler()]);
    }

    public function testConstructorRejectsValueNotImplementingHandlerInterface(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('Notification handler registry value must implement NotificationHandlerInterface.');

        // @phpstan-ignore argument.type
        new NotificationHandlerRegistry([InitializedNotification::method() => new \stdClass()]);
    }
}
