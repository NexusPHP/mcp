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
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\MetaObject\SubscriptionsListenResultMetaObject;
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
#[CoversClass(OpenSubscription::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class OpenSubscriptionTest extends AbstractMcpTestCase
{
    public function testCarriesEverythingAReopenNeeds(): void
    {
        /** @var DeferredFuture<SubscriptionsListenResult> $outcome */
        $outcome = new DeferredFuture();
        $seen = [];
        $filter = new SubscriptionFilter(toolsListChanged: true);

        $subscription = new OpenSubscription(
            new RequestId(id: 'feed-1'),
            $filter,
            static function (JsonRpcNotification $notification) use (&$seen): void {
                $seen[] = $notification::getMethod();
            },
            $outcome,
        );

        self::assertSame('feed-1', $subscription->subscriptionId->id);
        self::assertSame($filter, $subscription->notifications, 'The filter is replayed verbatim on a reconnect.');

        ($subscription->onNotification)(new ToolListChangedNotification());
        self::assertSame(['notifications/tools/list_changed'], $seen);

        $result = new SubscriptionsListenResult(new SubscriptionsListenResultMetaObject(subscriptionId: new RequestId(id: 'feed-1')));
        $subscription->outcome->complete($result);
        self::assertSame($result, $subscription->outcome->getFuture()->await());
    }
}
