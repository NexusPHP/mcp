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

use Amp\TimeoutCancellation;
use Nexus\Mcp\Client\Client;
use Nexus\Mcp\Client\ClientBuilder;
use Nexus\Mcp\Client\Exception\ClientAlreadyConnectedException;
use Nexus\Mcp\Client\Exception\ClientNotConnectedException;
use Nexus\Mcp\Client\Exception\ServerCapabilityNotSupportedException;
use Nexus\Mcp\Core\Exception\OutboundRequestFailedException;
use Nexus\Mcp\Core\Exception\RemoteCallFailedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\Prompt\PromptReference;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\CompleteRequest;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\Request\GetPromptRequest;
use Nexus\Mcp\Core\Schema\Request\ListPromptsRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourcesRequest;
use Nexus\Mcp\Core\Schema\Request\ListResourceTemplatesRequest;
use Nexus\Mcp\Core\Schema\Request\ListToolsRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\ResultMetaObject;
use Nexus\Mcp\Core\Schema\ResultResponse\ListToolsResultResponse;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\TransportInterface;
use Nexus\Mcp\Tests\Fixtures\Client\Http\MirroringRecordingTransport;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testConnectTwiceThrowsClientAlreadyConnectedException(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $client->connect(new RecordingTransport());

        $this->expectException(ClientAlreadyConnectedException::class);
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

    public function testSendRequestBeforeConnectThrowsClientNotConnectedException(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $request = new ListToolsRequest(id: new RequestId(id: 1), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create()));

        $this->expectException(ClientNotConnectedException::class);
        $this->expectExceptionMessageMatches('/not connected/');

        $client->sendRequest($request, ListToolsResultResponse::class);
    }

    public function testSendRequestRegistersTheIdAndSendsTheRequestOnTheTransport(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $request = new ListToolsRequest(id: new RequestId(id: 1), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create()));

        $deferredCall = async(static fn() => $client->sendRequest($request, ListToolsResultResponse::class));

        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        self::assertSame($request, $transport->sent[0]['message']);

        // Drive the inbound response so the future resolves.
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private']]);

        $response = $deferredCall->await();

        self::assertInstanceOf(JsonRpcResultResponse::class, $response);
        self::assertSame(1, $response->id->id);
        self::assertInstanceOf(ListToolsResult::class, $response->result);
    }

    public function testTransportCloseCancelsAllPendingOutboundRequestsWithTransportClosedException(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $request = new ListToolsRequest(id: new RequestId(id: 1), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create()));
        $call = async(static fn() => $client->sendRequest($request, ListToolsResultResponse::class));
        $transport->nextSend()->await();

        $transport->close();

        try {
            $call->await();
            self::fail('Expected TransportAlreadyClosedException after transport close cancels the await.');
        } catch (TransportAlreadyClosedException $e) {
            self::assertStringContainsString('await-response', $e->getMessage());
        }
    }

    public function testSendRequestForgetsTheRegistrationWhenTheTransportSendThrows(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $transport->sendError = new TransportAlreadyClosedException(operation: 'send-request');
        $client->connect($transport);

        $request = new ListToolsRequest(id: new RequestId(id: 1), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create()));

        try {
            $client->sendRequest($request, ListToolsResultResponse::class);
            self::fail('Expected the transport send failure to propagate.');
        } catch (TransportAlreadyClosedException) {
        }

        // Re-sending the same id surfaces the send failure again only if the
        // failed registration was freed. A leak would raise a duplicate-id error.
        try {
            $client->sendRequest($request, ListToolsResultResponse::class);
            self::fail('Expected the transport send failure to propagate.');
        } catch (TransportAlreadyClosedException $e) {
            self::assertStringContainsString('send-request', $e->getMessage());
        }
    }

    public function testDiscoverForgetsTheRegistrationWhenTheTransportSendThrows(): void
    {
        $client = new ClientBuilder()
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 1)
            ->build()
        ;
        $transport = new RecordingTransport();
        $transport->sendError = new TransportAlreadyClosedException(operation: 'send-request');
        $client->connect($transport);

        try {
            $client->discover();
            self::fail('Expected the transport send failure to propagate.');
        } catch (TransportAlreadyClosedException) {
        }

        // The second discover reuses the same minted id. It surfaces the send
        // failure again only if the failed registration was freed (a leak would
        // raise a duplicate-id error instead).
        try {
            $client->discover();
            self::fail('Expected the transport send failure to propagate.');
        } catch (TransportAlreadyClosedException $e) {
            self::assertStringContainsString('send-request', $e->getMessage());
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

    public function testAFailedExchangeFailsTheRequestItWasCarrying(): void
    {
        $logger = new ArrayLogger();
        $client = new ClientBuilder()->setLogger($logger)->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn(): DiscoverResult => $client->discover());
        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $sentRequest);

        $error = new OutboundRequestFailedException($sentRequest->id, new \RuntimeException('connection refused'));
        $transport->emitError($error);

        try {
            // Bounded: a client that does not fail the caller leaves this awaiting forever, and the point
            // of the test is that it must not. Without the bound that reads as a hang, not a failure.
            $deferred->await(new TimeoutCancellation(1.0));
            self::fail('Expected the caller to be failed rather than left awaiting a response that cannot arrive.');
        } catch (OutboundRequestFailedException $e) {
            self::assertSame($error, $e);
        }

        self::assertCount(1, $logger->recordsMatching(LogLevel::ERROR, 'Transport error.'), 'The fault is still logged.');
    }

    public function testAFailedExchangeLeavesOtherRequestsPending(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn(): DiscoverResult => $client->discover());
        $transport->nextSend()->await();

        // A failure naming an id nobody awaits must not disturb the request that is genuinely in flight.
        $transport->emitError(new OutboundRequestFailedException(new RequestId(id: 99), new \RuntimeException('connection refused')));

        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $sentRequest);
        $transport->emitMessage(self::discoverResponse($sentRequest->id->id, 'srv', '1.0'));

        self::assertInstanceOf(DiscoverResult::class, $deferred->await());
    }

    public function testDiscoverBeforeConnectThrowsClientNotConnectedException(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();

        $this->expectException(ClientNotConnectedException::class);
        $this->expectExceptionMessageMatches('/not connected/');

        $client->discover();
    }

    public function testDiscoverSendsRequestAndCachesServerInfoAndCapabilities(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.2.3')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->discover());

        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $sentRequest);

        $sentId = $sentRequest->id->id;
        self::assertSame(1, $sentId, 'Default factory mints the discover request id starting at 1.');
        self::assertSame(ProtocolVersion::LATEST_VERSION, $sentRequest->params->meta->protocolVersion->version);

        if (! $sentRequest->params->meta->clientInfo instanceof Implementation) {
            self::fail('Expected the client to stamp its own info onto the request "_meta".');
        }

        self::assertSame('demo', $sentRequest->params->meta->clientInfo->name);
        self::assertSame('1.2.3', $sentRequest->params->meta->clientInfo->version);
        self::assertSame([], $sentRequest->params->meta->clientCapabilities->toArray());

        $transport->emitMessage(self::discoverResponse($sentId, 'srv', '9.9'));

        $result = $deferred->await();

        self::assertInstanceOf(DiscoverResult::class, $result);

        if (! $result->meta->serverInfo instanceof Implementation) {
            self::fail('Expected the discover result "_meta" to carry the server info.');
        }

        self::assertSame('srv', $result->meta->serverInfo->name);

        // No notification follows the discover response.
        self::assertCount(1, $transport->sent);

        $serverInfo = $client->getServerInfo();
        self::assertNotNull($serverInfo);
        self::assertSame('srv', $serverInfo->name);
    }

    public function testSecondDiscoverPassesTheDefaultCapabilityGate(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        // The first discover caches the server capabilities, arming the gate.
        self::discover($client, $transport);

        // `server/discover` carries no capability requirement, so a second discover
        // (now that capabilities are cached) must clear the gate's `default` arm.
        $deferred = async(static fn() => $client->discover());
        $transport->nextSend()->await();
        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $request);

        $transport->emitMessage(self::discoverResponse($request->id->id));
        $result = $deferred->await();

        self::assertInstanceOf(DiscoverResult::class, $result);
    }

    public function testDiscoverStampsClientCapabilitiesIntoTheRequestMeta(): void
    {
        $capabilities = new ClientCapabilities(elicitation: []);
        $client = new ClientBuilder()
            ->setClientInfo('demo', '1.0.0')
            ->setClientCapabilities($capabilities)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->discover());
        $transport->nextSend()->await();

        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $sentRequest);
        self::assertSame($capabilities, $sentRequest->params->meta->clientCapabilities);
        self::assertSame(ProtocolVersion::LATEST_VERSION, $sentRequest->params->meta->protocolVersion->version);

        $transport->emitMessage(self::discoverResponse($sentRequest->id->id));

        $deferred->await();
    }

    public function testDiscoverPropagatesRemoteCallFailureWhenPeerReturnsError(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->discover());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $sentRequest = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $sentRequest);

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

        self::assertNull($client->getServerCapabilities(), 'A failed discover must not cache capabilities.');
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

    public function testGetServerInfoReturnsNullBeforeDiscovery(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();

        self::assertNull($client->getServerInfo());
    }

    public function testGetServerInfoReturnsImplementationCachedFromDiscovery(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport, 'srv', '9.9');

        $serverInfo = $client->getServerInfo();
        self::assertNotNull($serverInfo);
        self::assertSame('srv', $serverInfo->name);
        self::assertSame('9.9', $serverInfo->version);
    }

    public function testGetServerCapabilitiesReturnsNullBeforeDiscovery(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();

        self::assertNull($client->getServerCapabilities());
    }

    public function testGetServerCapabilitiesReturnsCapabilitiesCachedFromDiscovery(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport, capabilities: ['tools' => []]);

        $capabilities = $client->getServerCapabilities();
        self::assertInstanceOf(ServerCapabilities::class, $capabilities);
        self::assertSame([], $capabilities->tools);
        self::assertNull($capabilities->resources);
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('provideTypedCallThrowsWhenServerLacksTheCapabilityCases')]
    public function testTypedCallThrowsWhenServerLacksTheCapability(string $method, \Closure $call): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport, capabilities: []);

        $this->expectException(ServerCapabilityNotSupportedException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Request method "%s" requires a server capability that was not advertised during initialize.',
            $method,
        ));

        $call($client);
    }

    /**
     * @return iterable<string, array{0: string, 1: \Closure(Client): mixed}>
     */
    public static function provideTypedCallThrowsWhenServerLacksTheCapabilityCases(): iterable
    {
        yield 'tools/list' => ['tools/list', static fn(Client $client) => $client->listTools()];

        yield 'tools/call' => ['tools/call', static fn(Client $client) => $client->callTool('greet')];

        yield 'resources/list' => ['resources/list', static fn(Client $client) => $client->listResources()];

        yield 'resources/templates/list' => [
            'resources/templates/list',
            static fn(Client $client) => $client->listResourceTemplates(),
        ];

        yield 'resources/read' => ['resources/read', static fn(Client $client) => $client->readResource('example://x')];

        yield 'prompts/list' => ['prompts/list', static fn(Client $client) => $client->listPrompts()];

        yield 'prompts/get' => ['prompts/get', static fn(Client $client) => $client->getPrompt('walkthrough')];

        yield 'completion/complete' => [
            'completion/complete',
            static fn(Client $client) => $client->complete(
                new PromptReference(name: 'walkthrough'),
                ['name' => 'audience', 'value' => 'rev'],
            ),
        ];
    }

    public function testListToolsSendsRequestAndUnwrapsResult(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        $deferred = async(static fn() => $client->listTools());
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(ListToolsRequest::class, $request);
        self::assertNull($request->params->cursor);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
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
        self::discover($client, $transport);

        $cursor = new Cursor(cursor: 'page-2');
        $deferred = async(static fn() => $client->listTools($cursor));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(ListToolsRequest::class, $request);
        self::assertSame($cursor, $request->params->cursor);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
        ]);
        $deferred->await();
    }

    public function testListResourcesSendsRequestAndUnwrapsResult(): void
    {
        $client = new ClientBuilder()->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        $deferred = async(static fn() => $client->listResources());
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(ListResourcesRequest::class, $request);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['resources' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
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
        self::discover($client, $transport);

        $deferred = async(static fn() => $client->listResourceTemplates());
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(ListResourceTemplatesRequest::class, $request);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['resourceTemplates' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
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
        self::discover($client, $transport);

        $deferred = async(static fn() => $client->listPrompts());
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(ListPromptsRequest::class, $request);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['prompts' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
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
        self::discover($client, $transport);

        $deferred = async(static fn() => $client->readResource('example://greeting'));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(ReadResourceRequest::class, $request);
        self::assertSame('example://greeting', $request->params->uri);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['contents' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
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
        self::discover($client, $transport);

        $deferred = async(static fn() => $client->getPrompt('walkthrough', ['audience' => 'reviewers']));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
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
        self::discover($client, $transport);

        $ref = new PromptReference(name: 'walkthrough');
        $argument = ['name' => 'audience', 'value' => 'rev'];
        $deferred = async(static fn() => $client->complete($ref, $argument));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
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
        self::discover($client, $transport);

        $deferred = async(static fn() => $client->callTool('greet', ['name' => 'Paul']));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
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
        self::discover($client, $transport);

        /** @var list<array{float, ?float, ?string}> $received */
        $received = [];
        $onProgress = static function (float $progress, ?float $total, ?string $message) use (&$received): void {
            $received[] = [$progress, $total, $message];
        };
        $deferred = async(static fn() => $client->callTool('count_down', ['count' => 2], $onProgress));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
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
        self::discover($client, $transport);

        /** @var list<array{float, ?float, ?string}> $received */
        $received = [];
        $onProgress = static function (float $progress, ?float $total, ?string $message) use (&$received): void {
            $received[] = [$progress, $total, $message];
        };
        $deferred = async(static fn() => $client->callTool('count_down', ['count' => 1], $onProgress));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
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
        self::discover($client, $transport);

        $onProgress = static function (float $progress, ?float $total, ?string $message): void {};
        $deferred = async(static fn() => $client->callTool('greet', null, $onProgress));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
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
                ProgressNotification::getMethod(),
                new ClosureNotificationHandler(static function (JsonRpcNotification $n) use (&$delivered): void {
                    self::assertInstanceOf(ProgressNotification::class, $n);
                    $delivered[] = $n->params->progressToken->token;
                }),
            )
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        // No callTool in flight, so the token matches no per-call listener and falls through.
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => ['progressToken' => 'unsolicited', 'progress' => 0.5],
        ]);
        $transport->close();

        self::assertSame(['unsolicited'], $delivered);
    }

    public function testListToolsExcludesAToolWhoseDeclarationsAreInvalid(): void
    {
        // "Clients using the Streamable HTTP transport MUST reject tool definitions where any x-mcp-header
        // value violates these constraints ... the client MUST exclude the invalid tool."
        $logger = new ArrayLogger();
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport, $logger);

        $result = self::listToolsWithDeclarations($client, $transport);

        self::assertCount(1, $result->tools);
        self::assertSame('good', $result->tools[0]->name);

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Excluding tool {tool} from the listing: its "x-mcp-header" declarations are invalid.');
        self::assertCount(1, $matches);
        self::assertSame('bad', $matches[0]['context']['tool'] ?? null);
        self::assertIsString($matches[0]['context']['reason'] ?? null);
    }

    public function testListToolsKeepsEveryToolOnATransportThatDoesNotMirror(): void
    {
        // Stdio may ignore the annotations entirely, so an invalid one must not cost the user a usable tool.
        $logger = new ArrayLogger();
        $transport = new RecordingTransport();
        $client = self::connectMirroring($transport, $logger);

        $result = self::listToolsWithDeclarations($client, $transport);

        self::assertCount(2, $result->tools);
        self::assertSame([], $logger->recordsMatching(LogLevel::WARNING, 'Excluding tool {tool} from the listing: its "x-mcp-header" declarations are invalid.'));
    }

    public function testCallToolMirrorsAnnotatedArgumentsIntoHeaders(): void
    {
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport);
        self::listToolsWithDeclarations($client, $transport);

        self::callToolAndSettle($client, $transport, ['region' => 'us-west1', 'query' => 'SELECT 1']);

        self::assertSame(['Mcp-Param-Region' => 'us-west1'], self::lastContext($transport)->headers);
    }

    public function testCallToolOmitsAHeaderWhoseArgumentIsAbsent(): void
    {
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport);
        self::listToolsWithDeclarations($client, $transport);

        self::callToolAndSettle($client, $transport, ['query' => 'SELECT 1']);

        self::assertSame([], self::lastContext($transport)->headers);
    }

    public function testCallToolSendsNoHeadersForAToolItNeverListed(): void
    {
        // Nothing cached means nothing to mirror. The server answers HeaderMismatch and the client retries
        // after a fresh tools/list, which is the recovery the spec describes.
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport);

        self::callToolAndSettle($client, $transport, ['region' => 'us-west1']);

        self::assertSame([], self::lastContext($transport)->headers);
    }

    public function testReconnectingDiscardsTheCachedBindings(): void
    {
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport);
        self::listToolsWithDeclarations($client, $transport);

        $client->disconnect();
        $fresh = new MirroringRecordingTransport();
        $client->connect($fresh);

        self::callToolAndSettle($client, $fresh, ['region' => 'us-west1']);

        self::assertSame([], self::lastContext($fresh)->headers, 'The bindings belonged to the previous server.');
    }

    public function testCallToolSendsNoHeadersOnATransportThatDoesNotMirror(): void
    {
        $transport = new RecordingTransport();
        $client = self::connectMirroring($transport);
        self::listToolsWithDeclarations($client, $transport);

        self::callToolAndSettle($client, $transport, ['region' => 'us-west1']);

        self::assertSame([], self::lastContext($transport)->headers);
    }

    private static function connectMirroring(TransportInterface $transport, ?ArrayLogger $logger = null): Client
    {
        $client = new ClientBuilder()
            ->setClientInfo('demo', '1.0.0')
            ->setLogger($logger ?? new ArrayLogger())
            ->build()
        ;
        $client->connect($transport);

        return $client;
    }

    /**
     * Drives one `tools/list`, answering with a valid and an invalid `x-mcp-header` declaration.
     */
    private static function listToolsWithDeclarations(Client $client, MirroringRecordingTransport|RecordingTransport $transport): ListToolsResult
    {
        $deferred = async(static fn(): ListToolsResult => $client->listTools());
        $transport->nextSend()->await();

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'result' => [
                'tools' => [
                    [
                        'name' => 'bad',
                        // `number` is not a permitted x-mcp-header type. Listed first, so skipping it must
                        // not also skip what follows.
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => ['size' => ['type' => 'number', 'x-mcp-header' => 'Size']],
                        ],
                    ],
                    [
                        'name' => 'good',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                                'query' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
        ]);

        $result = $deferred->await();
        \assert($result instanceof ListToolsResult);

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function callToolAndSettle(Client $client, MirroringRecordingTransport|RecordingTransport $transport, array $arguments): void
    {
        $deferred = async(static fn() => $client->callTool('good', $arguments));
        $transport->nextSend()->await();

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'result' => ['content' => [], 'resultType' => 'complete'],
        ]);

        $deferred->await();
    }

    private static function lastContext(MirroringRecordingTransport|RecordingTransport $transport): SendContext
    {
        $context = self::lastSent($transport)['context'];

        if (! $context instanceof SendContext) {
            self::fail('The tool call carried no send context.');
        }

        return $context;
    }

    private static function lastRequestId(MirroringRecordingTransport|RecordingTransport $transport): int|string
    {
        $request = self::lastSent($transport)['message'];

        if (! $request instanceof JsonRpcRequest) {
            self::fail('The transport last recorded something other than a request.');
        }

        return $request->id->id;
    }

    /**
     * @return array{message: JsonRpcMessage, context: ?SendContext}
     */
    private static function lastSent(MirroringRecordingTransport|RecordingTransport $transport): array
    {
        $sent = $transport->sent;
        $last = end($sent);

        if (false === $last) {
            self::fail('The transport recorded no message.');
        }

        return $last;
    }

    /**
     * Runs `discover()` against the transport and resolves it with a synthetic
     * `DiscoverResult` envelope, leaving the discover request as `sent[0]`.
     *
     * @param array<string, mixed> $capabilities
     */
    private static function discover(
        Client $client,
        RecordingTransport $transport,
        string $serverName = 'srv',
        string $serverVersion = '1',
        array $capabilities = [
            'completions' => [],
            'prompts' => [],
            'resources' => [],
            'tools' => [],
        ],
    ): void {
        $deferred = async(static fn() => $client->discover());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $request = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $request);

        $transport->emitMessage(self::discoverResponse($request->id->id, $serverName, $serverVersion, $capabilities));
        $deferred->await();
    }

    /**
     * @param array<string, mixed> $capabilities
     *
     * @return array<string, mixed>
     */
    private static function discoverResponse(
        int|string $id,
        string $serverName = 'srv',
        string $serverVersion = '1',
        array $capabilities = [],
    ): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                '_meta' => [
                    ResultMetaObject::SERVER_INFO_KEY => ['name' => $serverName, 'version' => $serverVersion],
                ],
                'supportedVersions' => [ProtocolVersion::LATEST_VERSION],
                'protocolVersion' => ProtocolVersion::LATEST_VERSION,
                'capabilities' => $capabilities,
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
        ];
    }
}
