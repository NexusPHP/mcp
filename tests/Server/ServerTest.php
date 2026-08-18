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

namespace Nexus\Mcp\Tests\Server;

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use Nexus\Mcp\Server\Server;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\Subscription\SubscriptionStore;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(Server::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ServerTest extends AbstractMcpTestCase
{
    public function testAttachingANewTransportReopensADrainedStore(): void
    {
        $subscriptions = new SubscriptionStore(toolsListChanged: true);
        $server = (new ServerBuilder())->setServerInfo('demo', '1.0.0')->setSubscriptionStore($subscriptions)->build();

        $subscriptions->closeAll();
        $server->listen(new RecordingTransport());

        $entry = $subscriptions->open(new RequestId(id: 1), new SubscriptionFilter(toolsListChanged: true), new RecordingSender());

        self::assertFalse($entry->closed->isComplete(), 'A store reused on a new transport must serve live streams again.');
    }

    public function testRunStartsTheTransport(): void
    {
        $transport = new RecordingTransport();
        $server = self::buildServer();

        $serverRun = async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        EventLoop::queue(static function () use ($transport): void {
            self::assertTrue($transport->started);
            $transport->close();
        });

        $serverRun->await();
    }

    public function testRunBlocksUntilTransportCloses(): void
    {
        $transport = new RecordingTransport();
        $server = self::buildServer();
        $resolved = false;

        $serverRun = async(static function () use ($server, $transport, &$resolved): void {
            $server->run($transport);
            $resolved = true;
        });

        EventLoop::queue(static function () use ($transport, &$resolved): void {
            self::assertFalse($resolved);
            $transport->close();
        });

        $serverRun->await();
        self::assertTrue($resolved);
    }

    public function testInboundEnvelopeRoutesThroughDispatcher(): void
    {
        $transport = new RecordingTransport();
        $server = self::buildServer();

        $serverRun = async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'server/discover',
                'params' => ['_meta' => RequestMetaObjectFactory::shape()],
            ]);
            $transport->close();
        });

        $serverRun->await();

        self::assertCount(1, $transport->sent);
    }

    public function testTransportErrorIsLogged(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->setLogger($logger)
            ->build()
        ;

        $serverRun = async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitError(new \RuntimeException('transport boom'));
            $transport->close();
        });

        $serverRun->await();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Transport error.');
        self::assertCount(1, $matches);
        self::assertInstanceOf(\RuntimeException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testRunLogsStartupAndShutdown(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->setLogger($logger)
            ->build()
        ;

        $serverRun = async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->close();
        });

        $serverRun->await();

        $startup = $logger->recordsMatching(LogLevel::INFO, 'Starting MCP server.');
        self::assertCount(1, $startup);
        self::assertSame([], $startup[0]['context']);

        $shutdown = $logger->recordsMatching(LogLevel::INFO, 'MCP server stopped.');
        self::assertCount(1, $shutdown);
        self::assertSame([], $shutdown[0]['context']);
    }

    public function testTransportCloseIsIdempotent(): void
    {
        $transport = new RecordingTransport();
        $server = self::buildServer();

        $serverRun = async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->close();
            $transport->close();
        });

        $serverRun->await();
        $this->expectNotToPerformAssertions();
    }

    public function testCloseListenerFiringMoreThanOnceDoesNotCrashTheLoop(): void
    {
        $transport = new RecordingTransport();
        $server = self::buildServer();

        $serverRun = async(static function () use ($server, $transport): void {
            $server->run($transport);
        });

        EventLoop::queue(static function () use ($transport): void {
            $transport->emitClose();
            $transport->emitClose();
        });

        $serverRun->await();
        $this->expectNotToPerformAssertions();
    }

    public function testRunDrainsInFlightDispatchBeforeTransportFullyCloses(): void
    {
        [$serverSide, $clientSide] = InMemoryTransport::createPair();
        $server = self::buildServer();

        $clientReceived = [];
        $clientSide->onMessage(static function (array $envelope) use (&$clientReceived): void {
            $clientReceived[] = $envelope;
        });

        $serverRun = async(static function () use ($server, $serverSide): void {
            $server->run($serverSide);
        });

        EventLoop::queue(static function () use ($clientSide, $serverSide): void {
            $clientSide->start();
            $clientSide->send(new DiscoverRequest(
                id: new RequestId(id: 42),
                params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()),
            ));
            $serverSide->close();
        });

        $serverRun->await();

        self::assertCount(1, $clientReceived);
        self::assertSame(42, $clientReceived[0]['id'] ?? null);
        self::assertArrayHasKey('result', $clientReceived[0]);
    }

    public function testListenStartsTheTransportWithoutBlocking(): void
    {
        $transport = new RecordingTransport();
        $server = self::buildServer();

        $server->listen($transport);

        self::assertTrue($transport->started);
        self::assertFalse($transport->closed);
    }

    public function testListenRoutesInboundEnvelopesThroughDispatcher(): void
    {
        $transport = new RecordingTransport();
        $server = self::buildServer();

        $server->listen($transport);
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ]);

        EventLoop::run();

        self::assertCount(1, $transport->sent);
    }

    public function testADrainingTransportClosesEveryOpenSubscription(): void
    {
        $subscriptions = new SubscriptionStore();
        $transport = new RecordingTransport();
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->setSubscriptionStore($subscriptions)
            ->build()
        ;

        $server->listen($transport);
        $entry = $subscriptions->open(new RequestId(id: 1), new SubscriptionFilter(), new RecordingSender());
        $transport->close();

        self::assertTrue($entry->closed->isComplete());
    }

    public function testATransportReportedCancellationSuppressesTheResponse(): void
    {
        $transport = new RecordingTransport();
        $server = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('server/discover', new ClosureRequestHandler(
                static function ($request, AbstractContext $context): Result {
                    delay(1.0, cancellation: $context->cancellation);

                    return new EmptyResult();
                },
            ))
            ->build()
        ;

        $server->listen($transport);
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ]);
        $transport->emitCancel(new RequestId(id: 1));

        EventLoop::run();

        self::assertSame([], $transport->sent);
    }

    public function testAnUncancelledRequestIsStillAnsweredOnACancellableTransport(): void
    {
        $transport = new RecordingTransport();
        $server = self::buildServer();

        $server->listen($transport);
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ]);

        EventLoop::run();

        self::assertCount(1, $transport->sent);
    }

    private static function buildServer(): Server
    {
        return (new ServerBuilder())->setServerInfo('demo', '1.0.0')->build();
    }
}
