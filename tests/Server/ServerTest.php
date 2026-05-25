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

use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Transport\InMemoryTransport;
use Nexus\Mcp\Server\Server;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

use function Amp\async;

/**
 * @internal
 */
#[CoversClass(Server::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ServerTest extends TestCase
{
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
                'method' => 'ping',
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
        $server = new ServerBuilder()
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
        $server = new ServerBuilder()
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

    /**
     * Proves `run()` wires a drain listener to `dispatcher->flushPending()`.
     * `InMemoryTransport` throws `TransportAlreadyClosedException` on
     * send-after-close, so without the drain step the async dispatch coroutine
     * would lose the race and the client would never see a response.
     */
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
            $clientSide->send(new PingRequest(new RequestId(42)));
            $serverSide->close();
        });

        $serverRun->await();

        self::assertCount(1, $clientReceived);
        self::assertSame(42, $clientReceived[0]['id'] ?? null);
        self::assertArrayHasKey('result', $clientReceived[0]);
    }

    private static function buildServer(): Server
    {
        return new ServerBuilder()->setServerInfo('demo', '1.0.0')->build();
    }
}
