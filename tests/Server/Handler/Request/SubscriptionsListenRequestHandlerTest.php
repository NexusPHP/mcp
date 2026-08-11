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

namespace Nexus\Mcp\Tests\Server\Handler\Request;

use Amp\DeferredCancellation;
use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\Request\SubscriptionsListenRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\SubscriptionsListenRequestParams;
use Nexus\Mcp\Core\Schema\Result\SubscriptionsListenResult;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Server\Handler\Request\SubscriptionsListenRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Subscription\SubscriptionStore;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(SubscriptionsListenRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class SubscriptionsListenRequestHandlerTest extends AbstractMcpTestCase
{
    public function testHoldsTheRequestOpenUntilTheStreamIsTornDown(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $sender = new RecordingSender();
        $handler = new SubscriptionsListenRequestHandler($store);

        $running = async(static fn(): SubscriptionsListenResult => $handler->handle(
            self::listenRequest(1),
            self::contextFor(1, $sender),
        ));

        delay(0.0);
        self::assertFalse($running->isComplete(), 'The listen request stays open for the stream lifetime.');

        $store->closeAll();
        $running->await();

        self::assertTrue($running->isComplete());
    }

    public function testTheGracefulResultNamesTheStreamItCloses(): void
    {
        $store = new SubscriptionStore();
        $handler = new SubscriptionsListenRequestHandler($store);

        $running = async(static fn(): SubscriptionsListenResult => $handler->handle(
            self::listenRequest(1),
            self::contextFor(1, new RecordingSender()),
        ));
        delay(0.0);
        $store->closeAll();

        $result = $running->await();
        self::assertInstanceOf(SubscriptionsListenResult::class, $result);

        self::assertSame(1, $result->meta->subscriptionId->id);
        self::assertSame('complete', $result->toArray()['resultType']);
    }

    public function testTheSubscriptionIsNamedByTheIdThePeerSentNotTheDispatchedOne(): void
    {
        $store = new SubscriptionStore();
        $handler = new SubscriptionsListenRequestHandler($store);
        $sender = new RecordingSender();
        $context = new ServerContext(
            new RequestId(id: 41),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            $sender,
            new ReceiveContext(peerRequestId: new RequestId(id: 'client-7')),
        );

        $running = async(static fn(): SubscriptionsListenResult => $handler->handle(self::listenRequest(41), $context));
        delay(0.0);
        $store->closeAll();

        $result = $running->await();
        self::assertInstanceOf(SubscriptionsListenResult::class, $result);

        self::assertSame('client-7', $result->meta->subscriptionId->id);
        $ack = $sender->notifications[0] ?? null;
        self::assertNotNull($ack);
        $params = $ack->jsonSerialize()['params'] ?? [];
        self::assertIsArray($params);
        self::assertSame(['io.modelcontextprotocol/subscriptionId' => 'client-7'], $params['_meta'] ?? null);
    }

    public function testAnAbandonedStreamStopsTheHandlerAndDeregistersIt(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $sender = new RecordingSender();
        $handler = new SubscriptionsListenRequestHandler($store);
        $deferred = new DeferredCancellation();
        $context = new ServerContext(
            new RequestId(id: 1),
            $deferred->getCancellation(),
            RequestMetaObjectFactory::create(),
            $sender,
        );

        $running = async(static fn(): SubscriptionsListenResult => $handler->handle(self::listenRequest(1), $context));
        delay(0.0);
        $deferred->cancel();
        $running->await();

        $store->emitToolListChanged();
        delay(0.0);

        self::assertCount(1, $sender->notifications, 'A closed stream hears nothing more.');
    }

    public function testTheAcknowledgementOmitsATypeNoRegisteredStoreCanProduce(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true, promptsListChanged: true, resourcesListChanged: true);
        $sender = new RecordingSender();
        $handler = new SubscriptionsListenRequestHandler(
            $store,
            new SubscriptionFilter(promptsListChanged: true, resourcesListChanged: true),
        );

        $running = async(static fn(): SubscriptionsListenResult => $handler->handle(
            new SubscriptionsListenRequest(
                id: new RequestId(id: 1),
                params: new SubscriptionsListenRequestParams(
                    notifications: new SubscriptionFilter(toolsListChanged: true, promptsListChanged: true, resourcesListChanged: true),
                    meta: RequestMetaObjectFactory::create(),
                ),
            ),
            self::contextFor(1, $sender),
        ));
        delay(0.0);
        $store->closeAll();
        $running->await();

        $ack = $sender->notifications[0] ?? null;
        self::assertNotNull($ack);
        $params = $ack->jsonSerialize()['params'] ?? [];
        self::assertIsArray($params);
        self::assertSame(
            ['promptsListChanged' => true, 'resourcesListChanged' => true],
            $params['notifications'] ?? null,
            'The acknowledgement must promise only what a registered store can produce.',
        );
    }

    public function testResourceSubscriptionsPassTheDeliverableMaskUntouched(): void
    {
        $store = new SubscriptionStore(resourceSubscriptions: true);
        $sender = new RecordingSender();
        $handler = new SubscriptionsListenRequestHandler($store, new SubscriptionFilter());

        $running = async(static fn(): SubscriptionsListenResult => $handler->handle(
            new SubscriptionsListenRequest(
                id: new RequestId(id: 1),
                params: new SubscriptionsListenRequestParams(
                    notifications: new SubscriptionFilter(resourceSubscriptions: ['file:///a']),
                    meta: RequestMetaObjectFactory::create(),
                ),
            ),
            self::contextFor(1, $sender),
        ));
        delay(0.0);
        $store->closeAll();
        $running->await();

        $ack = $sender->notifications[0] ?? null;
        self::assertNotNull($ack);
        $params = $ack->jsonSerialize()['params'] ?? [];
        self::assertIsArray($params);
        self::assertSame(
            ['resourceSubscriptions' => ['file:///a']],
            $params['notifications'] ?? null,
            'Resource subscriptions are delivered by consumer emits, so the mask does not gate them.',
        );
    }

    /**
     * @param int|non-empty-string $id
     */
    private static function listenRequest(int|string $id): SubscriptionsListenRequest
    {
        return new SubscriptionsListenRequest(
            id: new RequestId(id: $id),
            params: new SubscriptionsListenRequestParams(
                notifications: new SubscriptionFilter(toolsListChanged: true),
                meta: RequestMetaObjectFactory::create(),
            ),
        );
    }

    /**
     * @param int|non-empty-string $id
     */
    private static function contextFor(int|string $id, RecordingSender $sender): ServerContext
    {
        return new ServerContext(
            new RequestId(id: $id),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            $sender,
        );
    }
}
