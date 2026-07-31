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

namespace Nexus\Mcp\Tests\Client\Subscription;

use Nexus\Mcp\Client\Subscription\SubscriptionListenerRegistry;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SubscriptionListenerRegistry::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class SubscriptionListenerRegistryTest extends TestCase
{
    public function testUnknownIdHasNoListener(): void
    {
        $registry = new SubscriptionListenerRegistry();

        self::assertNull($registry->get(new RequestId(1)));
    }

    public function testRegisteredListenerIsReturnedForItsId(): void
    {
        $registry = new SubscriptionListenerRegistry();
        $seen = [];
        $listener = static function (JsonRpcNotification $notification) use (&$seen): void {
            $seen[] = $notification::getMethod();
        };

        $registry->register(new RequestId(7), $listener);
        $resolved = $registry->get(new RequestId(7));

        self::assertNotNull($resolved);
        $resolved(new ToolListChangedNotification(new EmptyNotificationParams()));
        self::assertSame(['notifications/tools/list_changed'], $seen);
    }

    public function testUnregisterDropsTheListener(): void
    {
        $registry = new SubscriptionListenerRegistry();
        $registry->register(new RequestId(7), static function (JsonRpcNotification $notification): void {});

        $registry->unregister(new RequestId(7));

        self::assertNull($registry->get(new RequestId(7)));
    }

    public function testAnIntIdAndItsStringSpellingAreDistinct(): void
    {
        $registry = new SubscriptionListenerRegistry();
        $registry->register(new RequestId(7), static function (JsonRpcNotification $notification): void {});

        // MCP admits both int and string request ids, so "7" names a different stream than 7.
        self::assertNull($registry->get(new RequestId('7')));
    }
}
