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

use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Handler\SenderInterface;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
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

        self::assertSame(['notifications/subscriptions/acknowledged'], self::methodsOf($sender));
        self::assertSame(['io.modelcontextprotocol/subscriptionId' => 1], self::metaOf($sender, 0));
        self::assertSame(['toolsListChanged' => true], self::paramsOf($sender, 0)['notifications'] ?? null);
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

        self::assertSame(['toolsListChanged' => true], self::paramsOf($sender, 0)['notifications'] ?? null);
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
            self::methodsOf($sender),
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
            self::assertSame(['io.modelcontextprotocol/subscriptionId' => 'sub-a'], self::metaOf($sender, $index));
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
            self::methodsOf($sender),
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
        ], self::methodsOf($sender));
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
            self::methodsOf($watching),
        );
        self::assertSame(['notifications/subscriptions/acknowledged'], self::methodsOf($elsewhere));
        self::assertSame('file:///a', self::paramsOf($watching, 1)['uri'] ?? null);
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

        self::assertInstanceOf(\stdClass::class, self::paramsOf($sender, 0)['notifications'] ?? null);

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
            self::methodsOf($sender),
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

    public function testAnAllDigitResourceUriSurvivesTheArrayKeyCoercion(): void
    {
        $store = new SubscriptionStore(resourceSubscriptions: true);
        $sender = new RecordingSender();
        $store->open(new RequestId(id: 1), new SubscriptionFilter(resourceSubscriptions: ['123']), $sender);

        $store->emitResourceUpdated('123');
        delay(0.0);

        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/resources/updated'],
            self::methodsOf($sender),
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
            self::methodsOf($reachable),
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
            self::methodsOf($sender),
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

        self::assertSame([], self::methodsOf($sender), 'A refused stream is never acknowledged.');
    }

    #[DataProvider('provideTheSubscriptionLimitMustBePositiveCases')]
    public function testTheSubscriptionLimitMustBePositive(int $limit): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('The maximum open subscriptions must be a positive integer, %d given.', $limit));

        new SubscriptionStore(maxSubscriptions: $limit);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideTheSubscriptionLimitMustBePositiveCases(): iterable
    {
        yield 'zero' => [0];

        yield 'negative' => [-1];
    }

    public function testAStreamOpenedAfterTheDrainIsSettledAtOnce(): void
    {
        $store = new SubscriptionStore(toolsListChanged: true);
        $store->closeAll();

        $sender = new RecordingSender();
        $entry = $store->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), $sender);

        self::assertTrue($entry->closed->isComplete(), 'A late stream must not outlive the drain that already ran.');

        $store->close($entry);

        self::assertSame(['notifications/subscriptions/acknowledged'], self::methodsOf($sender));
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
            self::methodsOf($first),
            'Neither stream may evict the other.',
        );
        self::assertSame(
            ['notifications/subscriptions/acknowledged', 'notifications/tools/list_changed'],
            self::methodsOf($second),
        );

        $store->close($firstEntry);

        self::assertTrue($firstEntry->closed->isComplete());
        self::assertFalse($secondEntry->closed->isComplete(), 'Closing one stream must not tear down the other.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function paramsOf(RecordingSender $sender, int $index): array
    {
        $notification = $sender->notifications[$index] ?? null;

        if (! $notification instanceof JsonRpcNotification) {
            self::fail(\sprintf('Expected a notification at index %d.', $index));
        }

        $params = $notification->jsonSerialize()['params'] ?? [];
        self::assertIsArray($params);

        return $params;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function metaOf(RecordingSender $sender, int $index): array
    {
        $meta = self::paramsOf($sender, $index)['_meta'] ?? [];
        self::assertIsArray($meta);

        return $meta;
    }

    /**
     * @return list<string>
     */
    private static function methodsOf(RecordingSender $sender): array
    {
        return array_map(
            static fn(JsonRpcNotification $notification): string => $notification::getMethod(),
            $sender->notifications,
        );
    }
}
