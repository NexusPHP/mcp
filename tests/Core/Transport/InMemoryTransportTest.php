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

namespace Nexus\Mcp\Tests\Core\Transport;

use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InMemoryTransport::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class InMemoryTransportTest extends TestCase
{
    public function testPairProducesTwoDistinctTransports(): void
    {
        [$a, $b] = InMemoryTransport::createPair();

        self::assertNotSame($a, $b);
    }

    public function testSessionIdIsAlwaysNull(): void
    {
        [$a, $b] = InMemoryTransport::createPair();

        self::assertNull($a->getSessionId());
        self::assertNull($b->getSessionId());
    }

    public function testSendAfterBothStartedDeliversImmediately(): void
    {
        [$server, $client] = InMemoryTransport::createPair();
        $received = [];
        $server->onMessage(static function (array $envelope) use (&$received): void {
            $received[] = $envelope;
        });
        $server->start();
        $client->start();

        $client->send(new DiscoverRequest(new RequestId(42), new EmptyRequestParams(RequestMetaObjectFactory::create())));

        self::assertCount(1, $received);
        self::assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => 42,
                'method' => 'server/discover',
                'params' => ['_meta' => RequestMetaObjectFactory::shape()],
            ],
            $received[0],
        );
    }

    public function testInboundEnvelopesArrivingBeforeStartDrainOnStart(): void
    {
        [$server, $client] = InMemoryTransport::createPair();
        $received = new \ArrayObject();
        $server->onMessage(static function (array $envelope) use ($received): void {
            $received->append($envelope);
        });
        $client->start();
        $client->send(new ToolListChangedNotification());
        $client->send(new DiscoverRequest(new RequestId(1), new EmptyRequestParams(RequestMetaObjectFactory::create())));

        self::assertCount(0, $received, 'Server has not started yet, so envelopes must queue.');

        $server->start();

        self::assertSame(
            [
                ['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed'],
                [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'server/discover',
                    'params' => ['_meta' => RequestMetaObjectFactory::shape()],
                ],
            ],
            $received->getArrayCopy(),
        );
    }

    public function testSendBeforeStartThrowsTransportNotStarted(): void
    {
        [$a] = InMemoryTransport::createPair();

        $this->expectException(TransportNotStartedException::class);

        $a->send(new DiscoverRequest(new RequestId(1), new EmptyRequestParams(RequestMetaObjectFactory::create())));
    }

    public function testStartTwiceThrowsTransportAlreadyStarted(): void
    {
        [$a] = InMemoryTransport::createPair();
        $a->start();

        $this->expectException(TransportAlreadyStartedException::class);

        $a->start();
    }

    public function testStartAfterCloseThrowsTransportAlreadyClosed(): void
    {
        [$a] = InMemoryTransport::createPair();
        $a->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $a->start();
    }

    public function testSendAfterCloseThrowsTransportAlreadyClosed(): void
    {
        [$a] = InMemoryTransport::createPair();
        $a->start();
        $a->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $a->send(new DiscoverRequest(new RequestId(1), new EmptyRequestParams(RequestMetaObjectFactory::create())));
    }

    public function testCloseCascadesToPeerAndFiresCloseListenersOnBothSides(): void
    {
        [$a, $b] = InMemoryTransport::createPair();
        $aClosed = false;
        $bClosed = false;
        $a->onClose(static function () use (&$aClosed): void {
            $aClosed = true;
        });
        $b->onClose(static function () use (&$bClosed): void {
            $bClosed = true;
        });
        $a->start();
        $b->start();

        $a->close();

        self::assertTrue($aClosed);
        self::assertTrue($bClosed);
    }

    public function testCloseIsIdempotent(): void
    {
        [$a] = InMemoryTransport::createPair();
        $closeCount = 0;
        $a->onClose(static function () use (&$closeCount): void {
            ++$closeCount;
        });
        $a->start();

        $a->close();
        $a->close();
        $a->close();

        self::assertSame(1, $closeCount);
    }

    public function testCloseCascadeTransitionsPeerToClosedSoPeerSendFails(): void
    {
        [$server, $client] = InMemoryTransport::createPair();
        $server->start();
        $client->start();

        $server->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $client->send(new DiscoverRequest(new RequestId(1), new EmptyRequestParams(RequestMetaObjectFactory::create())));
    }

    public function testDisposingMessageSubscriptionStopsDelivery(): void
    {
        [$server, $client] = InMemoryTransport::createPair();
        $received = [];
        $subscription = $server->onMessage(static function (array $envelope) use (&$received): void {
            $received[] = $envelope;
        });
        $server->start();
        $client->start();

        $client->send(new DiscoverRequest(new RequestId(1), new EmptyRequestParams(RequestMetaObjectFactory::create())));
        self::assertCount(1, $received);

        $subscription->dispose();
        $client->send(new DiscoverRequest(new RequestId(2), new EmptyRequestParams(RequestMetaObjectFactory::create())));

        self::assertCount(1, $received, 'Disposed listener must not receive subsequent envelopes.');
    }

    public function testDisposingCloseSubscriptionStopsCloseNotification(): void
    {
        [$a] = InMemoryTransport::createPair();
        $closed = false;
        $subscription = $a->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        $subscription->dispose();
        $a->start();

        $a->close();

        self::assertFalse($closed);
    }

    public function testOnErrorReturnsSubscriptionEvenThoughNoErrorsAreEverFired(): void
    {
        [$a] = InMemoryTransport::createPair();

        $subscription = $a->onError(static fn(\Throwable $e) => null);
        $subscription->dispose();

        $this->expectNotToPerformAssertions();
    }

    public function testDrainListenerFiresBeforeCloseListenerOnClose(): void
    {
        [$a] = InMemoryTransport::createPair();
        $events = [];
        $a->onDrain(static function () use (&$events): void {
            $events[] = 'drain';
        });
        $a->onClose(static function () use (&$events): void {
            $events[] = 'close';
        });
        $a->start();

        $a->close();

        self::assertSame(['drain', 'close'], $events);
    }

    public function testDrainListenerThrowAbortsChainButCloseStillFires(): void
    {
        [$a] = InMemoryTransport::createPair();
        $secondDrainFired = false;
        $closed = false;
        $a->onDrain(static function (): void {
            throw new \RuntimeException('drain boom');
        });
        $a->onDrain(static function () use (&$secondDrainFired): void {
            $secondDrainFired = true;
        });
        $a->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        $a->start();

        try {
            $a->close();
        } catch (\RuntimeException) {
        }

        self::assertFalse($secondDrainFired);
        self::assertTrue($closed);
    }

    public function testDisposingDrainSubscriptionStopsDrainEmission(): void
    {
        [$a] = InMemoryTransport::createPair();
        $fired = false;
        $subscription = $a->onDrain(static function () use (&$fired): void {
            $fired = true;
        });
        $subscription->dispose();
        $a->start();

        $a->close();

        self::assertFalse($fired);
    }

    public function testMultipleListenersFireInRegistrationOrder(): void
    {
        [$server, $client] = InMemoryTransport::createPair();
        $order = [];
        $server->onMessage(static function () use (&$order): void {
            $order[] = 'first';
        });
        $server->onMessage(static function () use (&$order): void {
            $order[] = 'second';
        });
        $server->start();
        $client->start();

        $client->send(new DiscoverRequest(new RequestId(1), new EmptyRequestParams(RequestMetaObjectFactory::create())));

        self::assertSame(['first', 'second'], $order);
    }
}
