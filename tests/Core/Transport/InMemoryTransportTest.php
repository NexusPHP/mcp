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
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
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
        [$a, $b] = InMemoryTransport::pair();

        self::assertNotSame($a, $b);
    }

    public function testSessionIdIsAlwaysNull(): void
    {
        [$a, $b] = InMemoryTransport::pair();

        self::assertNull($a->sessionId());
        self::assertNull($b->sessionId());
    }

    public function testSendAfterBothStartedDeliversImmediately(): void
    {
        [$server, $client] = InMemoryTransport::pair();
        $received = [];
        $server->onMessage(static function (array $envelope) use (&$received): void {
            $received[] = $envelope;
        });
        $server->start();
        $client->start();

        $client->send(new PingRequest(new RequestId(42)));

        self::assertCount(1, $received);
        self::assertSame(['jsonrpc' => '2.0', 'id' => 42, 'method' => 'ping'], $received[0]);
    }

    public function testInboundEnvelopesArrivingBeforeStartDrainOnStart(): void
    {
        [$server, $client] = InMemoryTransport::pair();
        $received = new \ArrayObject();
        $server->onMessage(static function (array $envelope) use ($received): void {
            $received->append($envelope);
        });
        $client->start();
        $client->send(new InitializedNotification());
        $client->send(new PingRequest(new RequestId(1)));

        self::assertCount(0, $received, 'Server has not started yet, so envelopes must queue.');

        $server->start();

        self::assertSame(
            [
                ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ],
            $received->getArrayCopy(),
        );
    }

    public function testSendBeforeStartThrowsTransportNotStarted(): void
    {
        [$a] = InMemoryTransport::pair();

        $this->expectException(TransportNotStartedException::class);

        $a->send(new PingRequest(new RequestId(1)));
    }

    public function testStartTwiceThrowsTransportAlreadyStarted(): void
    {
        [$a] = InMemoryTransport::pair();
        $a->start();

        $this->expectException(TransportAlreadyStartedException::class);

        $a->start();
    }

    public function testStartAfterCloseThrowsTransportAlreadyClosed(): void
    {
        [$a] = InMemoryTransport::pair();
        $a->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $a->start();
    }

    public function testSendAfterCloseThrowsTransportAlreadyClosed(): void
    {
        [$a] = InMemoryTransport::pair();
        $a->start();
        $a->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $a->send(new PingRequest(new RequestId(1)));
    }

    public function testCloseCascadesToPeerAndFiresCloseListenersOnBothSides(): void
    {
        [$a, $b] = InMemoryTransport::pair();
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
        [$a] = InMemoryTransport::pair();
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
        [$server, $client] = InMemoryTransport::pair();
        $server->start();
        $client->start();

        $server->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $client->send(new PingRequest(new RequestId(1)));
    }

    public function testDisposingMessageSubscriptionStopsDelivery(): void
    {
        [$server, $client] = InMemoryTransport::pair();
        $received = [];
        $subscription = $server->onMessage(static function (array $envelope) use (&$received): void {
            $received[] = $envelope;
        });
        $server->start();
        $client->start();

        $client->send(new PingRequest(new RequestId(1)));
        self::assertCount(1, $received);

        $subscription->dispose();
        $client->send(new PingRequest(new RequestId(2)));

        self::assertCount(1, $received, 'Disposed listener must not receive subsequent envelopes.');
    }

    public function testDisposingCloseSubscriptionStopsCloseNotification(): void
    {
        [$a] = InMemoryTransport::pair();
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
        [$a] = InMemoryTransport::pair();

        $subscription = $a->onError(static fn(\Throwable $e) => null);
        $subscription->dispose();

        $this->expectNotToPerformAssertions();
    }

    public function testMultipleListenersFireInRegistrationOrder(): void
    {
        [$server, $client] = InMemoryTransport::pair();
        $order = [];
        $server->onMessage(static function () use (&$order): void {
            $order[] = 'first';
        });
        $server->onMessage(static function () use (&$order): void {
            $order[] = 'second';
        });
        $server->start();
        $client->start();

        $client->send(new PingRequest(new RequestId(1)));

        self::assertSame(['first', 'second'], $order);
    }
}
