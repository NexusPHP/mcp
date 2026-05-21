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

namespace Nexus\Mcp\Tests\Client;

use Nexus\Mcp\Client\Client;
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

use function Amp\async;

/**
 * @internal
 */
#[CoversClass(Client::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientTest extends TestCase
{
    public function testBuilderEntryPointReturnsFreshInstance(): void
    {
        $a = Client::builder();
        $b = Client::builder();

        self::assertNotSame($a, $b);
    }

    public function testConnectStartsTheTransportAndLogs(): void
    {
        $logger = new ArrayLogger();
        $client = new ClientBuilder()->setLogger($logger)->setClientInfo('demo', '1.2.3')->build();
        $transport = new RecordingTransport();

        $client->connect($transport);

        self::assertTrue($transport->started);
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Starting MCP client with {transport} client transport.');
        self::assertCount(1, $matches);
        self::assertSame(['transport' => 'recording'], $matches[0]['context']);
    }

    public function testConnectTwiceThrowsLogicException(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $client->connect(new RecordingTransport());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/already connected/');

        $client->connect(new RecordingTransport());
    }

    public function testSendRequestBeforeConnectThrowsLogicException(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $request = new PingRequest(new RequestId(1), new EmptyRequestParams());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/not connected/');

        $client->sendRequest($request, EmptyResult::class);
    }

    public function testSendRequestRegistersTheIdAndSendsTheRequestOnTheTransport(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $request = new PingRequest(new RequestId(1), new EmptyRequestParams());

        $deferredCall = async(static fn() => $client->sendRequest($request, EmptyResult::class));

        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        self::assertSame($request, $transport->sent[0]['message']);

        // Drive the inbound response so the future resolves.
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $response = $deferredCall->await();

        self::assertInstanceOf(JsonRpcResultResponse::class, $response);
        self::assertSame(1, $response->id->id);
        self::assertInstanceOf(EmptyResult::class, $response->result);
    }

    public function testTransportCloseCancelsAllPendingOutboundRequestsWithTransportClosedException(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $request = new PingRequest(new RequestId(1), new EmptyRequestParams());
        $call = async(static fn() => $client->sendRequest($request, EmptyResult::class));
        $transport->nextSend()->await();

        $transport->close();

        try {
            $call->await();
            self::fail('Expected TransportAlreadyClosedException after transport close cancels the await.');
        } catch (TransportAlreadyClosedException $e) {
            self::assertStringContainsString('await-response', $e->getMessage());
        }
    }

    public function testTransportErrorIsLoggedViaTheRegisteredErrorListener(): void
    {
        $logger = new ArrayLogger();
        $client = new ClientBuilder()->setLogger($logger)->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $error = new \RuntimeException('stream failure');
        $transport->emitError($error);

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Transport error.');
        self::assertCount(1, $matches);
        self::assertSame($error, $matches[0]['context']['exception'] ?? null);
    }

    public function testDrainFiresFlushPendingOnTheDispatcher(): void
    {
        $logger = new ArrayLogger();
        $client = new ClientBuilder()
            ->setLogger($logger)
            ->setClientInfo('demo', '1.0.0')
            ->addNotificationHandler(
                'notifications/cancelled',
                new ClosureNotificationHandler(static fn() => throw new \RuntimeException('handler ran')),
            )
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        // Spawn an in-flight notification handler.
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 1],
        ]);

        // close() emits the drain listener first. flushPending awaits the handler coroutine,
        // so the handler's RuntimeException reaches the logger before close completes.
        $transport->close();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Uncaught notification handler exception.');
        self::assertCount(1, $matches);
    }
}
