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
use Nexus\Mcp\Client\Exception\UnsupportedProtocolVersionException;
use Nexus\Mcp\Core\Exception\RemoteCallFailedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\InitializedNotification;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\InitializeRequest;
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Request\PingRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\InitializeResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

use function Amp\async;
use function Amp\delay;

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
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Starting MCP client.');
        self::assertCount(1, $matches);
        self::assertSame([], $matches[0]['context']);
    }

    public function testConnectTwiceThrowsLogicException(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $client->connect(new RecordingTransport());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/already connected/');

        $client->connect(new RecordingTransport());
    }

    public function testDisconnectClosesTheTransportAndAllowsReconnecting(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $first = new RecordingTransport();
        $client->connect($first);

        $client->disconnect();
        self::assertTrue($first->closed, 'disconnect() must close the attached transport.');

        $second = new RecordingTransport();
        $client->connect($second);
        self::assertTrue($second->started, 'disconnect() must detach the transport so a fresh connect() can run.');
    }

    public function testDisconnectIsANoOpWhenNotConnected(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();

        $client->disconnect();

        $this->expectNotToPerformAssertions();
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

    public function testInitializeThrowsAndWithholdsInitializedWhenServerVersionIsUnsupported(): void
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
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'serverInfo' => ['name' => 'srv', 'version' => '1'],
            ],
        ]);

        try {
            $deferred->await();
            self::fail('Expected the unsupported negotiated version to abort the handshake.');
        } catch (UnsupportedProtocolVersionException $e) {
            self::assertStringContainsString('2025-06-18', $e->getMessage());
            self::assertSame('2025-06-18', $e->negotiated->version);
        }

        self::assertCount(
            1,
            $transport->sent,
            'The client must not send notifications/initialized after rejecting the version.',
        );
        self::assertTrue($transport->closed, 'The client must disconnect on an unsupported negotiated version.');
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

    public function testGetServerInfoReturnsNullBeforeHandshake(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();

        self::assertNull($client->getServerInfo());
    }

    public function testGetServerInfoReturnsImplementationCachedFromHandshake(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport, 'srv', '9.9');

        $serverInfo = $client->getServerInfo();
        self::assertNotNull($serverInfo);
        self::assertSame('srv', $serverInfo->name);
        self::assertSame('9.9', $serverInfo->version);
    }

    public function testListToolsSendsRequestAndUnwrapsResult(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        $deferred = async(static fn() => $client->listTools());
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(ListToolsRequest::class, $request);
        self::assertNull($request->params->cursor);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['tools' => []],
        ]);

        $result = $deferred->await();
        self::assertInstanceOf(ListToolsResult::class, $result);
        self::assertSame([], $result->tools);
    }

    public function testListToolsForwardsCursorIntoParams(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        $cursor = new Cursor('page-2');
        $deferred = async(static fn() => $client->listTools($cursor));
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(ListToolsRequest::class, $request);
        self::assertSame($cursor, $request->params->cursor);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['tools' => []],
        ]);
        $deferred->await();
    }

    public function testListResourcesSendsRequestAndUnwrapsResult(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        $deferred = async(static fn() => $client->listResources());
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(ListResourcesRequest::class, $request);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['resources' => []],
        ]);

        $result = $deferred->await();
        self::assertInstanceOf(ListResourcesResult::class, $result);
        self::assertSame([], $result->resources);
    }

    public function testListResourceTemplatesSendsRequestAndUnwrapsResult(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        $deferred = async(static fn() => $client->listResourceTemplates());
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(ListResourceTemplatesRequest::class, $request);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['resourceTemplates' => []],
        ]);

        $result = $deferred->await();
        self::assertInstanceOf(ListResourceTemplatesResult::class, $result);
        self::assertSame([], $result->resourceTemplates);
    }

    public function testListPromptsSendsRequestAndUnwrapsResult(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        $deferred = async(static fn() => $client->listPrompts());
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(ListPromptsRequest::class, $request);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['prompts' => []],
        ]);

        $result = $deferred->await();
        self::assertInstanceOf(ListPromptsResult::class, $result);
        self::assertSame([], $result->prompts);
    }

    public function testReadResourceSendsRequestAndUnwrapsResult(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        $deferred = async(static fn() => $client->readResource('example://greeting'));
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(ReadResourceRequest::class, $request);
        self::assertSame('example://greeting', $request->params->uri);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['contents' => []],
        ]);

        $result = $deferred->await();
        self::assertInstanceOf(ReadResourceResult::class, $result);
        self::assertSame([], $result->contents);
    }

    public function testGetPromptForwardsNameAndArgumentsAndUnwrapsResult(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        $deferred = async(static fn() => $client->getPrompt('walkthrough', ['audience' => 'reviewers']));
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(GetPromptRequest::class, $request);
        self::assertSame('walkthrough', $request->params->name);
        self::assertSame(['audience' => 'reviewers'], $request->params->arguments);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['messages' => []],
        ]);

        $result = $deferred->await();
        self::assertInstanceOf(GetPromptResult::class, $result);
        self::assertSame([], $result->messages);
    }

    public function testCompleteForwardsRefAndArgumentAndUnwrapsResult(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        $ref = new PromptReference('walkthrough');
        $argument = ['name' => 'audience', 'value' => 'rev'];
        $deferred = async(static fn() => $client->complete($ref, $argument));
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(CompleteRequest::class, $request);
        self::assertSame($ref, $request->params->ref);
        self::assertSame($argument, $request->params->argument);
        self::assertNull($request->params->context);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['completion' => ['values' => ['reviewers', 'reviewing']]],
        ]);

        $result = $deferred->await();
        self::assertInstanceOf(CompleteResult::class, $result);
        self::assertSame(['values' => ['reviewers', 'reviewing']], $result->completion);
    }

    public function testCallToolWithoutProgressSendsRequestAndUnwrapsResult(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        $deferred = async(static fn() => $client->callTool('greet', ['name' => 'Paul']));
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(CallToolRequest::class, $request);
        self::assertSame('greet', $request->params->name);
        self::assertSame(['name' => 'Paul'], $request->params->arguments);
        self::assertNull($request->params->meta->progressToken, 'No progressToken without onProgress.');

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['content' => []],
        ]);

        $result = $deferred->await();
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertSame([], $result->content);
    }

    public function testCallToolWithProgressMintsTokenIntoMetaAndStreamsToCallback(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        /** @var list<array{float, ?float, ?string}> $received */
        $received = [];
        $onProgress = static function (float $progress, ?float $total, ?string $message) use (&$received): void {
            $received[] = [$progress, $total, $message];
        };
        $deferred = async(static fn() => $client->callTool('count_down', ['count' => 2], $onProgress));
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(CallToolRequest::class, $request);
        $progressToken = $request->params->meta->progressToken;
        self::assertNotNull($progressToken);

        // Server streams progress against the minted token while the call is in flight.
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => [
                'progressToken' => $progressToken->token,
                'progress' => 1.0,
                'total' => 2.0,
                'message' => '1 remaining',
            ],
        ]);

        // Let the tracked notification coroutine run before the result disposes the listener.
        delay(0.01);
        self::assertSame([[1.0, 2.0, '1 remaining']], $received);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['content' => []],
        ]);

        $result = $deferred->await();
        self::assertInstanceOf(CallToolResult::class, $result);
    }

    public function testCallToolDisposesProgressListenerAfterTheResponse(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        /** @var list<array{float, ?float, ?string}> $received */
        $received = [];
        $onProgress = static function (float $progress, ?float $total, ?string $message) use (&$received): void {
            $received[] = [$progress, $total, $message];
        };
        $deferred = async(static fn() => $client->callTool('count_down', ['count' => 1], $onProgress));
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(CallToolRequest::class, $request);
        $progressToken = $request->params->meta->progressToken;
        self::assertNotNull($progressToken);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['content' => []],
        ]);
        $deferred->await();

        // A late progress notification for the same token must no longer reach the callback.
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => ['progressToken' => $progressToken->token, 'progress' => 1.0],
        ]);
        $transport->close();

        self::assertSame([], $received);
    }

    public function testCallToolUsesTheInjectedProgressTokenFactory(): void
    {
        $client = new ClientBuilder()
            ->setClientInfo('demo', '1.0.0')
            ->setProgressTokenFactory(static fn(): string => 'custom-token')
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        $onProgress = static function (float $progress, ?float $total, ?string $message): void {};
        $deferred = async(static fn() => $client->callTool('greet', null, $onProgress));
        $transport->nextSend()->await();

        self::assertCount(3, $transport->sent);
        $request = $transport->sent[2]['message'];
        self::assertInstanceOf(CallToolRequest::class, $request);
        self::assertSame('custom-token', $request->params->meta->progressToken?->token);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['content' => []],
        ]);
        $deferred->await();
    }

    public function testBuildTimeProgressHandlerReceivesNotificationsWithNoPerCallListener(): void
    {
        /** @var list<int|string> $delivered */
        $delivered = [];
        $client = new ClientBuilder()
            ->setClientInfo('demo', '1.0.0')
            ->addNotificationHandler(
                ProgressNotification::method(),
                new ClosureNotificationHandler(static function (JsonRpcNotification $n) use (&$delivered): void {
                    self::assertInstanceOf(ProgressNotification::class, $n);
                    $delivered[] = $n->params->progressToken->token;
                }),
            )
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::handshake($client, $transport);

        // No callTool in flight, so the token matches no per-call listener and falls through.
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => ['progressToken' => 'unsolicited', 'progress' => 0.5],
        ]);
        $transport->close();

        self::assertSame(['unsolicited'], $delivered);
    }

    private static function handshake(
        Client $client,
        RecordingTransport $transport,
        string $serverName = 'srv',
        string $serverVersion = '1',
    ): void {
        $deferred = async(static fn() => $client->initialize());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $request = $transport->sent[0]['message'];
        self::assertInstanceOf(InitializeRequest::class, $request);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => [
                'protocolVersion' => ProtocolVersion::LATEST_VERSION,
                'capabilities' => [],
                'serverInfo' => ['name' => $serverName, 'version' => $serverVersion],
            ],
        ]);
        $deferred->await();
    }
}
