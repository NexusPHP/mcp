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
use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Schema\Request\SubscriptionsListenRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\SubscriptionsListenRequestParams;
use Nexus\Mcp\Core\Schema\Result\SubscriptionsListenResult;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Server\Exception\SubscriptionLimitReachedException;
use Nexus\Mcp\Server\Handler\Request\SubscriptionsListenRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Subscription\SubscriptionStore;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $running = async(fn(): SubscriptionsListenResult => $handler->handle(
            $this->listenRequest(1),
            $this->buildContextFor(1, $sender),
        ));

        delay(0.0);
        self::assertFalse($running->isComplete(), 'The listen request stays open for the stream lifetime.');

        $store->closeAll();
        $running->await();

        self::assertTrue($running->isComplete());
    }

    #[DataProvider('provideAStreamThatCanDeliverStaysOpenCases')]
    public function testAStreamThatCanDeliverStaysOpen(SubscriptionStore $store, SubscriptionFilter $requested): void
    {
        $handler = new SubscriptionsListenRequestHandler($store);

        $running = async(fn(): SubscriptionsListenResult => $handler->handle(
            $this->listenRequest(1, $requested),
            $this->buildContextFor(1, new RecordingSender()),
        ));
        delay(0.0);

        try {
            self::assertFalse($running->isComplete());
        } finally {
            $store->closeAll();
            $running->await();
        }
    }

    /**
     * @return iterable<string, array{SubscriptionStore, SubscriptionFilter}>
     */
    public static function provideAStreamThatCanDeliverStaysOpenCases(): iterable
    {
        yield 'tool list changes' => [new SubscriptionStore(toolsListChanged: true), new SubscriptionFilter(toolsListChanged: true)];

        yield 'prompt list changes' => [new SubscriptionStore(promptsListChanged: true), new SubscriptionFilter(promptsListChanged: true)];

        yield 'resource list changes' => [new SubscriptionStore(resourcesListChanged: true), new SubscriptionFilter(resourcesListChanged: true)];

        yield 'one resource' => [new SubscriptionStore(resourceSubscriptions: true), new SubscriptionFilter(resourceSubscriptions: ['file:///a'])];
    }

    #[DataProvider('provideAStreamThatHonoursNothingEndsAfterItsAcknowledgementCases')]
    public function testAStreamThatHonoursNothingEndsAfterItsAcknowledgement(SubscriptionStore $store, SubscriptionFilter $requested): void
    {
        $sender = new RecordingSender();
        $handler = new SubscriptionsListenRequestHandler($store);

        $running = async(fn(): SubscriptionsListenResult => $handler->handle(
            $this->listenRequest(1, $requested),
            $this->buildContextFor(1, $sender),
        ));
        delay(0.0);

        try {
            self::assertTrue($running->isComplete());
            self::assertCount(1, $sender->notifications);
            self::assertSame('notifications/subscriptions/acknowledged', $sender->notifications[0]::getMethod());
        } finally {
            $store->closeAll();
            $running->await();
        }
    }

    /**
     * @return iterable<string, array{SubscriptionStore, SubscriptionFilter}>
     */
    public static function provideAStreamThatHonoursNothingEndsAfterItsAcknowledgementCases(): iterable
    {
        yield 'a type the server does not back' => [new SubscriptionStore(), new SubscriptionFilter(toolsListChanged: true)];

        yield 'nothing requested' => [new SubscriptionStore(toolsListChanged: true), new SubscriptionFilter()];

        yield 'an empty resource list' => [new SubscriptionStore(resourceSubscriptions: true), new SubscriptionFilter(resourceSubscriptions: [])];
    }

    public function testAStreamThatHonoursNothingHoldsNoSlot(): void
    {
        $store = new SubscriptionStore(maxSubscriptionsPerPeer: 1);
        $handler = new SubscriptionsListenRequestHandler($store);

        $handler->handle($this->listenRequest(1), $this->buildAuthorizedContextFor(1, new RecordingSender(), clientId: 'cli-1', subject: null));
        $result = $handler->handle($this->listenRequest(2), $this->buildAuthorizedContextFor(2, new RecordingSender(), clientId: 'cli-1', subject: null));

        self::assertSame(2, $result->meta->subscriptionId->id);
    }

    public function testTheGracefulResultNamesTheStreamItCloses(): void
    {
        $store = new SubscriptionStore();
        $handler = new SubscriptionsListenRequestHandler($store);

        $running = async(fn(): SubscriptionsListenResult => $handler->handle(
            $this->listenRequest(1),
            $this->buildContextFor(1, new RecordingSender()),
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

        $running = async(fn(): SubscriptionsListenResult => $handler->handle($this->listenRequest(41), $context));
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

    public function testTheStreamIsBudgetedByTheTokensClientIdOverItsSubject(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true, maxSubscriptionsPerPeer: 1);
        $handler = new SubscriptionsListenRequestHandler($store);

        $running = async(fn(): SubscriptionsListenResult => $handler->handle(
            $this->listenRequest(1),
            $this->buildAuthorizedContextFor(1, new RecordingSender(), clientId: 'cli-1', subject: 'sub-a'),
        ));
        delay(0.0);

        try {
            $handler->handle(
                $this->listenRequest(2),
                $this->buildAuthorizedContextFor(2, new RecordingSender(), clientId: 'cli-1', subject: 'sub-b'),
            );
            self::fail('A second stream for the same OAuth client must be refused.');
        } catch (SubscriptionLimitReachedException $e) {
            self::assertSame('Subscription limit reached: this server holds at most 1 open streams per client.', $e->getMessage());
        }

        $store->closeAll();
        $running->await();
    }

    public function testTheStreamIsBudgetedByTheSubjectWhenTheTokenNamesNoClient(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true, maxSubscriptionsPerPeer: 1);
        $handler = new SubscriptionsListenRequestHandler($store);

        $running = async(fn(): SubscriptionsListenResult => $handler->handle(
            $this->listenRequest(1),
            $this->buildAuthorizedContextFor(1, new RecordingSender(), clientId: null, subject: 'sub-1'),
        ));
        delay(0.0);

        $this->expectException(SubscriptionLimitReachedException::class);

        try {
            $handler->handle(
                $this->listenRequest(2),
                $this->buildAuthorizedContextFor(2, new RecordingSender(), clientId: null, subject: 'sub-1'),
            );
        } finally {
            $store->closeAll();
            $running->await();
        }
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

        $running = async(fn(): SubscriptionsListenResult => $handler->handle($this->listenRequest(1), $context));
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

        $running = async(fn(): SubscriptionsListenResult => $handler->handle(
            new SubscriptionsListenRequest(
                id: new RequestId(id: 1),
                params: new SubscriptionsListenRequestParams(
                    notifications: new SubscriptionFilter(toolsListChanged: true, promptsListChanged: true, resourcesListChanged: true),
                    meta: RequestMetaObjectFactory::create(),
                ),
            ),
            $this->buildContextFor(1, $sender),
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

        $running = async(fn(): SubscriptionsListenResult => $handler->handle(
            new SubscriptionsListenRequest(
                id: new RequestId(id: 1),
                params: new SubscriptionsListenRequestParams(
                    notifications: new SubscriptionFilter(resourceSubscriptions: ['file:///a']),
                    meta: RequestMetaObjectFactory::create(),
                ),
            ),
            $this->buildContextFor(1, $sender),
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
    private function listenRequest(int|string $id, SubscriptionFilter $requested = new SubscriptionFilter(toolsListChanged: true)): SubscriptionsListenRequest
    {
        return new SubscriptionsListenRequest(
            id: new RequestId(id: $id),
            params: new SubscriptionsListenRequestParams(
                notifications: $requested,
                meta: RequestMetaObjectFactory::create(),
            ),
        );
    }

    /**
     * @param int|non-empty-string $id
     */
    private function buildContextFor(int|string $id, RecordingSender $sender): ServerContext
    {
        return new ServerContext(
            new RequestId(id: $id),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            $sender,
        );
    }

    /**
     * @param int|non-empty-string  $id
     * @param null|non-empty-string $clientId
     * @param null|non-empty-string $subject
     */
    private function buildAuthorizedContextFor(int|string $id, RecordingSender $sender, ?string $clientId, ?string $subject): ServerContext
    {
        return new ServerContext(
            new RequestId(id: $id),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            $sender,
            new ReceiveContext(authInfo: new VerifiedAccessToken(
                audience: ['https://mcp.test'],
                expiresAt: 2_000_000_000,
                subject: $subject,
                clientId: $clientId,
            )),
        );
    }
}
