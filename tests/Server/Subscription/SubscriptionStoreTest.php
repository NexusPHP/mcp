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

namespace Nexus\Mcp\Tests\Server\Subscription;

use Amp\DeferredFuture;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Handler\SenderInterface;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Server\Exception\SubscriptionLimitReachedException;
use Nexus\Mcp\Server\Subscription\SubscriptionStore;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(SubscriptionStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class SubscriptionStoreTest extends AbstractMcpTestCase
{
    public function testOpeningAStreamAcknowledgesItFirst(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $sender = new RecordingSender();

        $store->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), $sender);

        self::assertSame(['notifications/subscriptions/acknowledged'], $this->readMethodsOf($sender));
        self::assertSame(['io.modelcontextprotocol/subscriptionId' => 1], $this->readMetaOf($sender, 0));
        self::assertSame(['toolsListChanged' => true], $this->readParamsOf($sender, 0)['notifications'] ?? null);
    }

    public function testTheAcknowledgementOmitsTypesTheServerCannotDeliver(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $sender = new RecordingSender();

        $store->open(
            new RequestId(id: 1),
            new SubscriptionFilter(toolsListChanged: true, promptsListChanged: true, resourcesListChanged: true),
            $sender,
        );

        self::assertSame(['toolsListChanged' => true], $this->readParamsOf($sender, 0)['notifications'] ?? null);
    }

    public function testAStreamHearsOnlyTheTypesItRequested(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true, promptsListChanged: true);
        $sender = new RecordingSender();
        $store->open(new RequestId(id: 1), new SubscriptionFilter(promptsListChanged: true), $sender);

        $store->emitToolListChanged();
        $store->emitPromptListChanged();
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/prompts/list_changed'],
            $this->readMethodsOf($sender),
        );
    }

    public function testEveryStreamMessageCarriesItsSubscriptionId(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $sender = new RecordingSender();
        $store->open(new RequestId(id: 'sub-a'), new SubscriptionFilter(toolsListChanged: true), $sender);

        $store->emitToolListChanged();
        delay(0.0);

        self::assertCount(2, $sender->notifications);

        foreach (array_keys($sender->notifications) as $index) {
            self::assertSame(['io.modelcontextprotocol/subscriptionId' => 'sub-a'], $this->readMetaOf($sender, $index));
        }
    }

    public function testABurstOfChangesReachesTheStreamOnce(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $sender = new RecordingSender();
        $store->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), $sender);

        $store->emitToolListChanged();
        $store->emitToolListChanged();
        $store->emitToolListChanged();
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/tools/list_changed'],
            $this->readMethodsOf($sender),
        );
    }

    public function testChangesInSeparateTicksEachReachTheStream(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $sender = new RecordingSender();
        $store->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), $sender);

        $store->emitToolListChanged();
        delay(0.0);
        $store->emitToolListChanged();
        delay(0.0);

        self::assertCount(3, $sender->notifications);
    }

    /**
     * @param \Closure(SubscriptionStore): void $emit
     */
    #[DataProvider('provideAListChangeEmittedOnItsOwnReachesTheStreamCases')]
    public function testAListChangeEmittedOnItsOwnReachesTheStream(\Closure $emit, string $expectedMethod): void
    {
        $store = new SubscriptionStore(toolsListChanged: true, promptsListChanged: true, resourcesListChanged: true);
        $sender = new RecordingSender();
        $store->open(
            new RequestId(id: 1),
            new SubscriptionFilter(toolsListChanged: true, promptsListChanged: true, resourcesListChanged: true),
            $sender,
        );

        $emit($store);
        delay(0.0);

        self::assertSame(['notifications/subscriptions/acknowledged', $expectedMethod], $this->readMethodsOf($sender));
    }

    /**
     * @return iterable<string, array{\Closure(SubscriptionStore): void, string}>
     */
    public static function provideAListChangeEmittedOnItsOwnReachesTheStreamCases(): iterable
    {
        yield 'tools' => [static fn(SubscriptionStore $store) => $store->emitToolListChanged(), 'notifications/tools/list_changed'];

        yield 'prompts' => [static fn(SubscriptionStore $store) => $store->emitPromptListChanged(), 'notifications/prompts/list_changed'];

        yield 'resources' => [static fn(SubscriptionStore $store) => $store->emitResourceListChanged(), 'notifications/resources/list_changed'];
    }

    public function testEachListChangeKindIsAnnouncedSeparately(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true, promptsListChanged: true, resourcesListChanged: true);
        $sender = new RecordingSender();
        $store->open(
            new RequestId(id: 1),
            new SubscriptionFilter(toolsListChanged: true, promptsListChanged: true, resourcesListChanged: true),
            $sender,
        );

        $store->emitToolListChanged();
        $store->emitPromptListChanged();
        $store->emitResourceListChanged();
        delay(0.0);

        self::assertSame([
            'notifications/subscriptions/acknowledged',
            'notifications/tools/list_changed',
            'notifications/prompts/list_changed',
            'notifications/resources/list_changed',
        ], $this->readMethodsOf($sender));
    }

    public function testAResourceUpdateReachesOnlyTheStreamsWatchingThatUri(): void
    {
        $store = new SubscriptionStore(resourceSubscriptions: true);
        $watching = new RecordingSender();
        $elsewhere = new RecordingSender();
        $store->open(new RequestId(id: 1), new SubscriptionFilter(resourceSubscriptions: ['file:///a']), $watching);
        $store->open(new RequestId(id: 2), new SubscriptionFilter(resourceSubscriptions: ['file:///b']), $elsewhere);

        $store->emitResourceUpdated('file:///a');
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/resources/updated'],
            $this->readMethodsOf($watching),
        );
        self::assertSame(['notifications/subscriptions/acknowledged'], $this->readMethodsOf($elsewhere));
        self::assertSame('file:///a', $this->readParamsOf($watching, 1)['uri'] ?? null);
    }

    public function testDistinctResourceUrisAreEachAnnounced(): void
    {
        $store = new SubscriptionStore(resourceSubscriptions: true);
        $sender = new RecordingSender();
        $store->open(
            new RequestId(id: 1),
            new SubscriptionFilter(resourceSubscriptions: ['file:///a', 'file:///b']),
            $sender,
        );

        $store->emitResourceUpdated('file:///a');
        $store->emitResourceUpdated('file:///a');
        $store->emitResourceUpdated('file:///b');
        delay(0.0);

        self::assertCount(3, $sender->notifications, 'The repeat collapses, the distinct URI does not.');
    }

    public function testAnEmptyResourceUriIsRejected(): void
    {
        $store = new SubscriptionStore(resourceSubscriptions: true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('An updated resource URI must be a non-empty string.');

        $store->emitResourceUpdated('');
    }

    public function testResourceUpdatesAreWithheldWhenTheServerCannotDeliverThem(): void
    {
        $store = new SubscriptionStore();
        $sender = new RecordingSender();
        $store->open(new RequestId(id: 1), new SubscriptionFilter(resourceSubscriptions: ['file:///a']), $sender);

        $store->emitResourceUpdated('file:///a');
        delay(0.0);

        self::assertCount(1, $sender->notifications);
    }

    public function testAnUninterestedStreamDoesNotStopDeliveryToTheOnesBehindIt(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $uninterested = new RecordingSender();
        $interested = new RecordingSender();
        $store->open(new RequestId(id: 1), new SubscriptionFilter(), $uninterested);
        $store->open(new RequestId(id: 2), new SubscriptionFilter(toolsListChanged: true), $interested);

        $store->emitToolListChanged();
        delay(0.0);

        self::assertCount(1, $uninterested->notifications);
        self::assertCount(2, $interested->notifications, 'Skipping one stream must not end the fan-out.');
    }

    public function testAStreamWatchingAnotherUriDoesNotStopDeliveryToTheOnesBehindIt(): void
    {
        $store = new SubscriptionStore(resourceSubscriptions: true);
        $elsewhere = new RecordingSender();
        $watching = new RecordingSender();
        $store->open(new RequestId(id: 1), new SubscriptionFilter(resourceSubscriptions: ['file:///b']), $elsewhere);
        $store->open(new RequestId(id: 2), new SubscriptionFilter(resourceSubscriptions: ['file:///a']), $watching);

        $store->emitResourceUpdated('file:///a');
        delay(0.0);

        self::assertCount(1, $elsewhere->notifications);
        self::assertCount(2, $watching->notifications, 'Skipping one stream must not end the fan-out.');
    }

    public function testAStoreThatSupportsNothingHonoursNothing(): void
    {
        $store = new SubscriptionStore();
        $sender = new RecordingSender();
        $store->open(
            new RequestId(id: 1),
            new SubscriptionFilter(toolsListChanged: true, promptsListChanged: true, resourcesListChanged: true),
            $sender,
        );

        self::assertInstanceOf(\stdClass::class, $this->readParamsOf($sender, 0)['notifications'] ?? null);

        $store->emitToolListChanged();
        $store->emitPromptListChanged();
        $store->emitResourceListChanged();
        delay(0.0);

        self::assertCount(1, $sender->notifications);
    }

    public function testClosingAStreamReleasesItsHandlerAndStopsDelivery(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $sender = new RecordingSender();
        $entry = $store->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), $sender);

        $store->close($entry);
        $store->emitToolListChanged();
        delay(0.0);

        self::assertTrue($entry->closed->isComplete());
        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/cancelled'],
            $this->readMethodsOf($sender),
            'The spec has the server name the listen request it is tearing down.',
        );
    }

    public function testClosingAStreamAnotherStoreOwnsIsANoOp(): void
    {
        $store = new SubscriptionStore();
        $foreign = (new SubscriptionStore())->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender());

        $store->close($foreign);

        self::assertFalse($foreign->closed->isComplete());
    }

    public function testClosingTwiceIsHarmless(): void
    {
        $store = new SubscriptionStore();
        $entry = $store->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender());

        $store->close($entry);
        $store->close($entry);

        self::assertTrue($entry->closed->isComplete());
    }

    public function testTheTeardownCancellationCarriesTheSubscriptionId(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $sender = new RecordingSender();
        $entry = $store->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), $sender);

        $store->close($entry);
        delay(0.0);

        $cancellation = $sender->notifications[1] ?? null;
        self::assertInstanceOf(CancelledNotification::class, $cancellation);
        self::assertSame(
            1,
            $cancellation->params->meta->subscriptionId?->id,
            'The spec has the server tag every notification delivered on a stream with that stream id.',
        );
    }

    public function testCloseAllReleasesEveryStream(): void
    {
        $store = new SubscriptionStore();
        $first = $store->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender());
        $second = $store->open(new RequestId(id: 2), new SubscriptionFilter(), new RecordingSender());

        $store->closeAll();

        self::assertTrue($first->closed->isComplete());
        self::assertTrue($second->closed->isComplete());
    }

    public function testAStreamOpenedAfterTheDrainSettlesAtOnce(): void
    {
        $store = new SubscriptionStore();
        $store->closeAll();

        $entry = $store->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender());

        self::assertTrue($entry->closed->isComplete());
    }

    public function testReopenLetsAReusedStoreServeLiveStreamsAgain(): void
    {
        $store = new SubscriptionStore();
        $store->closeAll();
        $store->reopen();

        $entry = $store->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender());

        self::assertFalse($entry->closed->isComplete());
    }

    public function testAnAllDigitResourceUriSurvivesTheArrayKeyCoercion(): void
    {
        $store = new SubscriptionStore(resourceSubscriptions: true);
        $sender = new RecordingSender();
        $store->open(new RequestId(id: 1), new SubscriptionFilter(resourceSubscriptions: ['123']), $sender);

        $store->emitResourceUpdated('123');
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/resources/updated'],
            $this->readMethodsOf($sender),
        );

        $updated = $sender->notifications[1] ?? null;
        self::assertNotNull($updated);
        $params = $updated->jsonSerialize()['params'] ?? [];
        self::assertIsArray($params);
        self::assertSame('123', $params['uri'] ?? null);
    }

    public function testAStreamThatCannotTakeANotificationDoesNotStopTheOnesBehindIt(): void
    {
        $logger = new ArrayLogger();
        $store = new SubscriptionStore(toolsListChanged: true, logger: $logger);
        $filter = new SubscriptionFilter(toolsListChanged: true);
        $reachable = new RecordingSender();

        $sends = 0;
        $vanished = self::createStub(SenderInterface::class);
        $vanished->method('sendNotification')->willReturnCallback(
            static function () use (&$sends): void {
                if (++$sends > 1) {
                    throw new TransportAlreadyClosedException('send a notification');
                }
            },
        );

        $store->open(new RequestId(id: 1), $filter, $vanished);
        $store->open(new RequestId(id: 2), $filter, $reachable);

        $store->emitToolListChanged();
        delay(0.0);

        $matches = $logger->recordsMatching(LogLevel::DEBUG, 'Dropping a subscription notification its stream could not take.');
        self::assertCount(1, $matches);
        self::assertSame('notifications/tools/list_changed', $matches[0]['context']['method'] ?? null);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/tools/list_changed'],
            $this->readMethodsOf($reachable),
            'A stream whose peer vanished must not cost the streams behind it.',
        );
    }

    public function testAStreamClosedBeforeTheFlushRunsHearsNothingFurther(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $sender = new RecordingSender();
        $entry = $store->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), $sender);

        $store->emitToolListChanged();
        $entry->closed->complete();
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged'],
            $this->readMethodsOf($sender),
            'A settled stream is terminal, so nothing may follow its result.',
        );
    }

    public function testOpeningPastTheSubscriptionLimitIsRefusedBeforeTheAcknowledgement(): void
    {
        $store = new SubscriptionStore(maxSubscriptions: 1);
        $store->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender());
        $sender = new RecordingSender();

        try {
            $store->open(new RequestId(id: 2), new SubscriptionFilter(), $sender);
            self::fail('The store must refuse a stream past its limit.');
        } catch (SubscriptionLimitReachedException $e) {
            self::assertSame('Subscription limit reached: this server holds at most 1 open streams.', $e->getMessage());
            self::assertSame(ProtocolErrorCode::InternalError, $e::getErrorCode());
        }

        self::assertSame([], $this->readMethodsOf($sender), 'A refused stream is never acknowledged.');
    }

    public function testOpeningPastThePerPeerLimitIsRefused(): void
    {
        $store = new SubscriptionStore(maxSubscriptionsPerPeer: 1);
        $store->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender(), peer: 'alice');
        $sender = new RecordingSender();

        try {
            $store->open(new RequestId(id: 2), new SubscriptionFilter(), $sender, peer: 'alice');
            self::fail('The store must refuse a stream past its per-peer limit.');
        } catch (SubscriptionLimitReachedException $e) {
            self::assertSame('Subscription limit reached: this server holds at most 1 open streams per client.', $e->getMessage());
            self::assertSame(ProtocolErrorCode::InternalError, $e::getErrorCode());
        }

        self::assertSame([], $this->readMethodsOf($sender), 'A refused stream is never acknowledged.');
    }

    public function testOpeningAStreamNamingTooManyResourceUrisIsRefusedBeforeAnySlotIsSpent(): void
    {
        $store = new SubscriptionStore(resourceSubscriptions: true, maxSubscriptions: 1, maxSubscriptionsPerPeer: 1, maxResourceSubscriptionsPerStream: 1);
        $sender = new RecordingSender();

        try {
            $store->open(new RequestId(id: 1), new SubscriptionFilter(resourceSubscriptions: ['file:///a', 'file:///b']), $sender, peer: 'alice');
            self::fail('The store must refuse a stream naming more URIs than one may watch.');
        } catch (SubscriptionLimitReachedException $e) {
            self::assertSame('Subscription limit reached: this server watches at most 1 resource URIs per stream.', $e->getMessage());
            self::assertSame(ProtocolErrorCode::InternalError, $e::getErrorCode());
        }

        self::assertSame([], $this->readMethodsOf($sender), 'A refused stream is never acknowledged.');

        $entry = $store->open(new RequestId(id: 2), new SubscriptionFilter(resourceSubscriptions: ['file:///a']), new RecordingSender(), peer: 'alice');

        self::assertFalse($entry->closed->isComplete(), 'A refused stream spends neither the server-wide nor the per-peer slot.');
    }

    public function testAResourceListTheServerCannotHonourDoesNotCountAgainstTheStreamBudget(): void
    {
        $store = new SubscriptionStore(maxResourceSubscriptionsPerStream: 1);

        $entry = $store->open(new RequestId(id: 1), new SubscriptionFilter(resourceSubscriptions: ['file:///a', 'file:///b']), new RecordingSender());

        self::assertFalse($entry->closed->isComplete());
        self::assertNull($entry->honoured->resourceSubscriptions);
    }

    public function testAStreamNamingAUriTwiceHearsItsUpdateOnce(): void
    {
        $store = new SubscriptionStore(resourceSubscriptions: true);
        $sender = new RecordingSender();
        $store->open(new RequestId(id: 1), new SubscriptionFilter(resourceSubscriptions: ['file:///a', 'file:///a']), $sender);

        $store->emitResourceUpdated('file:///a');
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/resources/updated'],
            $this->readMethodsOf($sender),
        );
    }

    public function testClosingOneWatcherOfAUriLeavesTheOtherHearingIt(): void
    {
        $store = new SubscriptionStore(resourceSubscriptions: true);
        $leaving = new RecordingSender();
        $staying = new RecordingSender();
        $entry = $store->open(new RequestId(id: 1), new SubscriptionFilter(resourceSubscriptions: ['file:///a']), $leaving);
        $store->open(new RequestId(id: 2), new SubscriptionFilter(resourceSubscriptions: ['file:///a']), $staying);

        $store->close($entry);
        $store->emitResourceUpdated('file:///a');
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/cancelled'],
            $this->readMethodsOf($leaving),
        );
        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/resources/updated'],
            $this->readMethodsOf($staying),
        );
    }

    public function testDiscardingAStreamStopsItsResourceUpdates(): void
    {
        $store = new SubscriptionStore(resourceSubscriptions: true);
        $sender = new RecordingSender();
        $entry = $store->open(new RequestId(id: 1), new SubscriptionFilter(resourceSubscriptions: ['file:///a']), $sender);

        $store->discard($entry);
        $store->emitResourceUpdated('file:///a');
        delay(0.0);

        self::assertSame(['notifications/subscriptions/acknowledged'], $this->readMethodsOf($sender));
    }

    public function testDiscardingAStreamAnotherStoreOwnsLeavesThisStoreIntact(): void
    {
        $other = new SubscriptionStore(resourceSubscriptions: true);
        $foreign = $other->open(new RequestId(id: 1), new SubscriptionFilter(resourceSubscriptions: ['file:///a']), new RecordingSender());
        $store = new SubscriptionStore(resourceSubscriptions: true);
        $sender = new RecordingSender();
        $store->open(new RequestId(id: 2), new SubscriptionFilter(resourceSubscriptions: ['file:///a']), $sender);

        $store->discard($foreign);
        $store->emitResourceUpdated('file:///a');
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/resources/updated'],
            $this->readMethodsOf($sender),
        );
    }

    public function testDistinctPeersSpendSeparateBudgets(): void
    {
        $store = new SubscriptionStore(maxSubscriptionsPerPeer: 1);
        $store->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender(), peer: 'alice');

        $entry = $store->open(new RequestId(id: 2), new SubscriptionFilter(), new RecordingSender(), peer: 'bob');

        self::assertFalse($entry->closed->isComplete());
    }

    public function testAnAnonymousStreamSpendsNoPerPeerBudget(): void
    {
        $store = new SubscriptionStore(maxSubscriptionsPerPeer: 1);
        $store->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender());

        $entry = $store->open(new RequestId(id: 2), new SubscriptionFilter(), new RecordingSender());

        self::assertFalse($entry->closed->isComplete());
    }

    public function testClosingAStreamReleasesItsPeerSlot(): void
    {
        $store = new SubscriptionStore(maxSubscriptionsPerPeer: 2);
        $first = $store->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender(), peer: 'alice');
        $store->open(new RequestId(id: 2), new SubscriptionFilter(), new RecordingSender(), peer: 'alice');

        $store->close($first);
        $store->open(new RequestId(id: 3), new SubscriptionFilter(), new RecordingSender(), peer: 'alice');

        $this->expectException(SubscriptionLimitReachedException::class);
        $this->expectExceptionMessageIs('Subscription limit reached: this server holds at most 2 open streams per client.');

        $store->open(new RequestId(id: 4), new SubscriptionFilter(), new RecordingSender(), peer: 'alice');
    }

    public function testAFailedAcknowledgementReleasesThePeerSlot(): void
    {
        $store = new SubscriptionStore(maxSubscriptionsPerPeer: 1);
        $vanished = self::createStub(SenderInterface::class);
        $vanished->method('sendNotification')->willThrowException(new TransportAlreadyClosedException('send a notification'));

        try {
            $store->open(new RequestId(id: 1), new SubscriptionFilter(), $vanished, peer: 'alice');
            self::fail('The acknowledgement failure must surface.');
        } catch (TransportAlreadyClosedException) {
        }

        $entry = $store->open(new RequestId(id: 2), new SubscriptionFilter(), new RecordingSender(), peer: 'alice');

        self::assertFalse($entry->closed->isComplete());
    }

    public function testAStreamSettledByTheDrainReleasesThePeerSlot(): void
    {
        $store = new SubscriptionStore(maxSubscriptionsPerPeer: 1);
        $store->closeAll();
        $store->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender(), peer: 'alice');

        $store->reopen();
        $entry = $store->open(new RequestId(id: 2), new SubscriptionFilter(), new RecordingSender(), peer: 'alice');

        self::assertFalse($entry->closed->isComplete());
    }

    public function testAStreamWhoseAcknowledgementIsStillInFlightHoldsItsSlotAgainstTheLimit(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true, maxSubscriptions: 1);

        /** @var DeferredFuture<null> $gate */
        $gate = new DeferredFuture();
        $parked = new class ($gate) implements SenderInterface {
            /**
             * @param DeferredFuture<null> $gate
             */
            public function __construct(private readonly DeferredFuture $gate)
            {
            }

            #[\Override]
            public function sendNotification(JsonRpcNotification $notification): void
            {
                $this->gate->getFuture()->await();
            }

            #[\Override]
            public function sendRequest(JsonRpcRequest $request): never
            {
                throw new \BadMethodCallException('The store sends no requests.');
            }
        };

        $first = async(static fn(): mixed => $store->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), $parked));
        delay(0.0);

        $sender = new RecordingSender();

        try {
            $store->open(new RequestId(id: 2), new SubscriptionFilter(toolsListChanged: true), $sender);
            self::fail('A slot held by a suspended acknowledgement must still count against the limit.');
        } catch (SubscriptionLimitReachedException $e) {
            self::assertSame('Subscription limit reached: this server holds at most 1 open streams.', $e->getMessage());
        }

        self::assertSame([], $this->readMethodsOf($sender), 'A refused stream is never acknowledged.');

        $gate->complete(null);
        $first->await();

        try {
            $store->open(new RequestId(id: 3), new SubscriptionFilter(toolsListChanged: true), new RecordingSender());
            self::fail('The registered stream must occupy the slot its reservation held.');
        } catch (SubscriptionLimitReachedException $e) {
            self::assertSame('Subscription limit reached: this server holds at most 1 open streams.', $e->getMessage());
        }
    }

    public function testAStreamWhoseAcknowledgementFailedReleasesItsSlot(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true, maxSubscriptions: 1);

        $vanished = self::createStub(SenderInterface::class);
        $vanished->method('sendNotification')->willThrowException(new TransportAlreadyClosedException('send a notification'));

        try {
            $store->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), $vanished);
            self::fail('A failed acknowledgement must reach the caller.');
        } catch (TransportAlreadyClosedException $e) {
            self::assertSame('Cannot send a notification on a closed transport.', $e->getMessage());
        }

        $sender = new RecordingSender();
        $store->open(new RequestId(id: 2), new SubscriptionFilter(toolsListChanged: true), $sender);

        self::assertSame(['notifications/subscriptions/acknowledged'], $this->readMethodsOf($sender));
    }

    #[DataProvider('provideTheSubscriptionLimitMustBePositiveCases')]
    public function testTheSubscriptionLimitMustBePositive(mixed $limit, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type
        new SubscriptionStore(maxSubscriptions: $limit);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideTheSubscriptionLimitMustBePositiveCases(): iterable
    {
        yield 'zero' => [0, 'The maximum open subscriptions must be a positive integer, 0 given.'];

        yield 'negative' => [-1, 'The maximum open subscriptions must be a positive integer, -1 given.'];
    }

    #[DataProvider('provideThePerPeerSubscriptionLimitMustBePositiveCases')]
    public function testThePerPeerSubscriptionLimitMustBePositive(mixed $limit, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type
        new SubscriptionStore(maxSubscriptionsPerPeer: $limit);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideThePerPeerSubscriptionLimitMustBePositiveCases(): iterable
    {
        yield 'zero' => [0, 'The maximum open subscriptions per peer must be a positive integer, 0 given.'];

        yield 'negative' => [-1, 'The maximum open subscriptions per peer must be a positive integer, -1 given.'];
    }

    #[DataProvider('provideThePerStreamResourceSubscriptionLimitMustBePositiveCases')]
    public function testThePerStreamResourceSubscriptionLimitMustBePositive(mixed $limit, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type
        new SubscriptionStore(maxResourceSubscriptionsPerStream: $limit);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideThePerStreamResourceSubscriptionLimitMustBePositiveCases(): iterable
    {
        yield 'zero' => [0, 'The maximum resource subscriptions per stream must be a positive integer, 0 given.'];

        yield 'negative' => [-1, 'The maximum resource subscriptions per stream must be a positive integer, -1 given.'];
    }

    public function testAStreamOpenedAfterTheDrainIsSettledAtOnce(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $store->closeAll();

        $sender = new RecordingSender();
        $entry = $store->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), $sender);

        self::assertTrue($entry->closed->isComplete(), 'A late stream must not outlive the drain that already ran.');

        $store->close($entry);

        self::assertSame(['notifications/subscriptions/acknowledged'], $this->readMethodsOf($sender));
    }

    public function testTwoStreamsSharingASubscriptionIdStayIndependent(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $first = new RecordingSender();
        $second = new RecordingSender();
        $filter = new SubscriptionFilter(toolsListChanged: true);

        $firstEntry = $store->open(new RequestId(id: 1), $filter, $first);
        $secondEntry = $store->open(new RequestId(id: 1), $filter, $second);

        $store->emitToolListChanged();
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/tools/list_changed'],
            $this->readMethodsOf($first),
            'Neither stream may evict the other.',
        );
        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/tools/list_changed'],
            $this->readMethodsOf($second),
        );

        $store->close($firstEntry);

        self::assertTrue($firstEntry->closed->isComplete());
        self::assertFalse($secondEntry->closed->isComplete(), 'Closing one stream must not tear down the other.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readParamsOf(RecordingSender $sender, int $index): array
    {
        $notification = $sender->notifications[$index] ?? null;

        self::assertInstanceOf(JsonRpcNotification::class, $notification);

        $params = $notification->jsonSerialize()['params'] ?? [];
        self::assertIsArray($params);

        return $params;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readMetaOf(RecordingSender $sender, int $index): array
    {
        $meta = $this->readParamsOf($sender, $index)['_meta'] ?? [];
        self::assertIsArray($meta);

        return $meta;
    }

    /**
     * @return list<string>
     */
    private function readMethodsOf(RecordingSender $sender): array
    {
        return array_map(
            static fn(JsonRpcNotification $notification): string => $notification::getMethod(),
            $sender->notifications,
        );
    }
}
