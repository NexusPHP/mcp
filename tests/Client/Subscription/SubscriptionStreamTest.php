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
use Nexus\Mcp\Client\Exception\SubscriptionClosedException;
use Nexus\Mcp\Client\Subscription\SubscriptionStream;
use Nexus\Mcp\Core\Exception\RemoteCallFailedException;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\MetaObject\SubscriptionsListenResultMetaObject;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\SubscriptionsListenResult;
use Nexus\Mcp\Core\Schema\ResultResponse\SubscriptionsListenResultResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SubscriptionStream::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class SubscriptionStreamTest extends TestCase
{
    public function testExposesTheSubscriptionId(): void
    {
        /** @var DeferredFuture<SubscriptionsListenResultResponse> $deferred */
        $deferred = new DeferredFuture();
        $stream = new SubscriptionStream(new RequestId(7), $deferred->getFuture(), static function (): void {});

        self::assertSame(7, $stream->subscriptionId->id);
    }

    public function testCloseRunsTheTeardownExactlyOnce(): void
    {
        /** @var DeferredFuture<SubscriptionsListenResultResponse> $deferred */
        $deferred = new DeferredFuture();
        $closes = 0;
        $stream = new SubscriptionStream(new RequestId(7), $deferred->getFuture(), static function () use (&$closes): void {
            ++$closes;
        });

        $stream->close();
        $stream->close();

        self::assertSame(1, $closes);
    }

    public function testAwaitReturnsTheServersTeardownResult(): void
    {
        /** @var DeferredFuture<SubscriptionsListenResultResponse> $deferred */
        $deferred = new DeferredFuture();
        $stream = new SubscriptionStream(new RequestId(7), $deferred->getFuture(), static function (): void {});

        $result = new SubscriptionsListenResult(new SubscriptionsListenResultMetaObject(subscriptionId: new RequestId(7)));
        $deferred->complete(new SubscriptionsListenResultResponse(id: new RequestId(7), result: $result));

        self::assertSame($result, $stream->await());
    }

    public function testAwaitSurfacesARefusedSubscription(): void
    {
        /** @var DeferredFuture<SubscriptionsListenResultResponse> $deferred */
        $deferred = new DeferredFuture();
        $stream = new SubscriptionStream(new RequestId(7), $deferred->getFuture(), static function (): void {});

        $deferred->error(new RemoteCallFailedException(new InternalError('Subscriptions are not served.')));

        $this->expectException(RemoteCallFailedException::class);
        $stream->await();
    }

    public function testAwaitingAClosedStreamThrowsRatherThanBlocking(): void
    {
        /** @var DeferredFuture<SubscriptionsListenResultResponse> $deferred */
        $deferred = new DeferredFuture();
        $stream = new SubscriptionStream(new RequestId(7), $deferred->getFuture(), static function (): void {});
        $deferred->getFuture()->ignore();

        $stream->close();

        // The spec answers an abrupt close with nothing, so awaiting would suspend the caller for good.
        $this->expectException(SubscriptionClosedException::class);
        $stream->await();
    }
}
