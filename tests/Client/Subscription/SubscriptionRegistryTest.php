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

use Amp\DeferredFuture;
use Nexus\Mcp\Client\Subscription\OpenSubscription;
use Nexus\Mcp\Client\Subscription\SubscriptionRegistry;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\SubscriptionsListenResult;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SubscriptionRegistry::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class SubscriptionRegistryTest extends AbstractMcpTestCase
{
    public function testUnknownIdHasNoSubscription(): void
    {
        $registry = new SubscriptionRegistry();

        self::assertNull($registry->get(new RequestId(id: 1)));
    }

    public function testRegisteredSubscriptionIsReturnedForItsId(): void
    {
        $registry = new SubscriptionRegistry();
        $seen = [];

        $registry->register($this->buildSubscription(new RequestId(id: 7), static function (JsonRpcNotification $notification) use (&$seen): void {
            $seen[] = $notification::getMethod();
        }));
        $resolved = $registry->get(new RequestId(id: 7));

        if (null === $resolved) {
            self::fail('The registered subscription should resolve for its own id.');
        }

        ($resolved->onNotification)(new ToolListChangedNotification());
        self::assertSame(['notifications/tools/list_changed'], $seen);
        self::assertSame(7, $resolved->subscriptionId->id);
    }

    public function testForgetReturnsTheSubscriptionAndDropsIt(): void
    {
        $registry = new SubscriptionRegistry();
        $subscription = $this->buildSubscription(new RequestId(id: 7));
        $registry->register($subscription);

        self::assertSame($subscription, $registry->forget(new RequestId(id: 7)));
        self::assertNull($registry->get(new RequestId(id: 7)));
    }

    public function testForgettingAnUnknownIdReturnsNull(): void
    {
        $registry = new SubscriptionRegistry();

        self::assertNull($registry->forget(new RequestId(id: 7)));
    }

    public function testAllReturnsEverySubscriptionInRegistrationOrder(): void
    {
        $registry = new SubscriptionRegistry();
        $first = $this->buildSubscription(new RequestId(id: 1));
        $second = $this->buildSubscription(new RequestId(id: 'feed'));
        $registry->register($first);
        $registry->register($second);

        self::assertSame([$first, $second], $registry->all());
    }

    public function testAllIsEmptyOnAFreshRegistry(): void
    {
        self::assertSame([], (new SubscriptionRegistry())->all());
    }

    public function testReregisteringAnIdReplacesTheSubscription(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->register($this->buildSubscription(new RequestId(id: 7)));
        $replacement = $this->buildSubscription(new RequestId(id: 7));
        $registry->register($replacement);

        self::assertSame([$replacement], $registry->all());
    }

    public function testDrainEmptiesTheRegistryAndReturnsWhatItHeld(): void
    {
        $registry = new SubscriptionRegistry();
        $first = $this->buildSubscription(new RequestId(id: 1));
        $second = $this->buildSubscription(new RequestId(id: 2));
        $registry->register($first);
        $registry->register($second);

        self::assertSame([$first, $second], $registry->drain());
        self::assertSame([], $registry->all());
        self::assertNull($registry->get(new RequestId(id: 1)));
    }

    public function testDrainingAnEmptyRegistryReturnsNothing(): void
    {
        self::assertSame([], (new SubscriptionRegistry())->drain());
    }

    public function testAnIntIdAndItsStringSpellingAreDistinct(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->register($this->buildSubscription(new RequestId(id: 7)));

        self::assertNull($registry->get(new RequestId(id: '7')));
    }

    /**
     * @param null|\Closure(JsonRpcNotification<non-empty-string>): void $onNotification
     */
    private function buildSubscription(RequestId $id, ?\Closure $onNotification = null): OpenSubscription
    {
        /** @var DeferredFuture<SubscriptionsListenResult> $outcome */
        $outcome = new DeferredFuture();

        return new OpenSubscription(
            $id,
            new SubscriptionFilter(),
            $onNotification ?? static function (JsonRpcNotification $notification): void {},
            $outcome,
        );
    }
}
