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
use Nexus\Mcp\Core\Exception\RemoteCallFailedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\InitializeResult;
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
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Starting MCP client with {transport} transport.');
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

    public function testInitializeBeforeConnectThrowsLogicException(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/not connected/');

        $client->initialize();
    }

    public function testInitializeSendsRequestAwaitsResultThenSendsNotification(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.2.3')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->initialize());

        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        self::assertInstanceOf(InitializeRequest::class, $transport->sent[0]['message']);

        $sentRequest = $transport->sent[0]['message'];
        $sentId = $sentRequest->id->id;
        self::assertSame(1, $sentId, 'Default factory mints the handshake request id starting at 1.');
        self::assertSame(ProtocolVersion::LATEST_VERSION, $sentRequest->params->protocolVersion->version);
        self::assertSame('demo', $sentRequest->params->clientInfo->name);
        self::assertSame('1.2.3', $sentRequest->params->clientInfo->version);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sentId,
            'result' => [
                'protocolVersion' => ProtocolVersion::LATEST_VERSION,
                'capabilities' => [],
                'serverInfo' => ['name' => 'srv', 'version' => '9.9'],
            ],
        ]);

        $result = $deferred->await();

        self::assertInstanceOf(InitializeResult::class, $result);
        self::assertSame('srv', $result->serverInfo->name);

        self::assertCount(2, $transport->sent);
        self::assertInstanceOf(InitializedNotification::class, $transport->sent[1]['message']);
    }

    public function testInitializeForwardsCapabilitiesAndProtocolVersion(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $capabilities = new ClientCapabilities(sampling: []);
        $protocolVersion = new ProtocolVersion(ProtocolVersion::LATEST_VERSION);

        $deferred = async(static fn() => $client->initialize($capabilities, $protocolVersion));
        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(InitializeRequest::class, $sentRequest);
        self::assertSame($capabilities, $sentRequest->params->capabilities);
        self::assertSame($protocolVersion, $sentRequest->params->protocolVersion);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sentRequest->id->id,
            'result' => [
                'protocolVersion' => ProtocolVersion::LATEST_VERSION,
                'capabilities' => [],
                'serverInfo' => ['name' => 'srv', 'version' => '1'],
            ],
        ]);

        $deferred->await();
    }

    public function testInitializeRejectsReentryWhileInFlight(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $first = async(static fn() => $client->initialize());
        $transport->nextSend()->await();

        try {
            $client->initialize();
            self::fail('Expected LogicException for re-entry while a handshake was in flight.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('already started or completed', $e->getMessage());
        }

        $first->ignore();
    }

    public function testInitializeRejectsAfterSuccessfulHandshake(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->initialize());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(InitializeRequest::class, $sentRequest);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sentRequest->id->id,
            'result' => [
                'protocolVersion' => ProtocolVersion::LATEST_VERSION,
                'capabilities' => [],
                'serverInfo' => ['name' => 'srv', 'version' => '1'],
            ],
        ]);
        $deferred->await();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/already started or completed/');

        $client->initialize();
    }

    public function testInitializeRevertsGateWhenPeerReturnsError(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->initialize());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(InitializeRequest::class, $sentRequest);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sentRequest->id->id,
            'error' => ['code' => -32603, 'message' => 'peer refused'],
        ]);

        try {
            $deferred->await();
            self::fail('Expected RemoteCallFailedException.');
        } catch (RemoteCallFailedException $e) {
            self::assertSame('peer refused', $e->getMessage());
        }

        // Gate reverted: a fresh handshake is now allowed.
        $retry = async(static fn() => $client->initialize());
        $transport->nextSend()->await();
        self::assertCount(2, $transport->sent, 'A second initialize must be sendable after revert.');
        $retry->ignore();
    }

    public function testSendRequestAfterHandshakeAllowsArbitraryMethods(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->initialize());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $initializeRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(InitializeRequest::class, $initializeRequest);
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $initializeRequest->id->id,
            'result' => [
                'protocolVersion' => ProtocolVersion::LATEST_VERSION,
                'capabilities' => [],
                'serverInfo' => ['name' => 'srv', 'version' => '1'],
            ],
        ]);
        $deferred->await();

        // Without markInitialized() firing, the gate would remain in InitializeInFlight
        // and reject this non-handshake non-ping request.
        $followUp = async(static fn() => $client->sendRequest(
            new ListToolsRequest(new RequestId(2)),
            EmptyResult::class,
        ));
        $transport->nextSend()->await();
        self::assertCount(3, $transport->sent);
        self::assertInstanceOf(ListToolsRequest::class, $transport->sent[2]['message']);
        $followUp->ignore();
    }

    public function testSendRequestBeforeHandshakeRejectsArbitraryMethods(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/cannot be sent before the client handshake completes/');

        $client->sendRequest(new ListToolsRequest(new RequestId(99)), EmptyResult::class);
    }

    public function testSendRequestPingIsAllowedBeforeHandshake(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $request = new PingRequest(new RequestId(7), new EmptyRequestParams());
        $deferred = async(static fn() => $client->sendRequest($request, EmptyResult::class));
        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        self::assertSame($request, $transport->sent[0]['message']);
        $deferred->ignore();
    }

    public function testInitializedNotificationCarriesNoParams(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->initialize());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(InitializeRequest::class, $sentRequest);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $sentRequest->id->id,
            'result' => [
                'protocolVersion' => ProtocolVersion::LATEST_VERSION,
                'capabilities' => [],
                'serverInfo' => ['name' => 'srv', 'version' => '1'],
            ],
        ]);
        $deferred->await();

        self::assertCount(2, $transport->sent);
        $notification = $transport->sent[1]['message'];
        self::assertInstanceOf(InitializedNotification::class, $notification);
        self::assertSame('notifications/initialized', $notification::method());
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
