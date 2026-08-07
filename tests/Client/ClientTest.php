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
use Nexus\Mcp\Client\Dispatch\ClientMessageDispatcher;
use Nexus\Mcp\Client\Exception\ClientAlreadyConnectedException;
use Nexus\Mcp\Client\Exception\ClientNotConnectedException;
use Nexus\Mcp\Client\Exception\ServerCapabilityNotSupportedException;
use Nexus\Mcp\Client\Exception\SubscriptionClosedException;
use Nexus\Mcp\Client\Transport\SupervisedTransport;
use Nexus\Mcp\Core\Dispatch\PendingOutboundRequests;
use Nexus\Mcp\Core\Exception\DuplicateOutboundRequestIdException;
use Nexus\Mcp\Core\Exception\OutboundRequestFailedException;
use Nexus\Mcp\Core\Exception\RemoteCallFailedException;
use Nexus\Mcp\Core\Exception\RequestTimeoutException;
use Nexus\Mcp\Core\Exception\SupervisionExhaustedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Handler\HandlerRegistry;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcNotification;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\MetaObject\NotificationMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\SubscriptionsListenResultMetaObject;
use Nexus\Mcp\Core\Schema\Notification\CancelledNotification;
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
use Nexus\Mcp\Core\Schema\Request\SubscriptionsListenRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\GetPromptRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\PaginatedRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\ReadResourceRequestParams;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\CompleteResult;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ListPromptsResult;
use Nexus\Mcp\Core\Schema\Result\ListResourcesResult;
use Nexus\Mcp\Core\Schema\Result\ListResourceTemplatesResult;
use Nexus\Mcp\Core\Schema\Result\ListToolsResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\GetPromptResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ListToolsResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\ReadResourceResultResponse;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Core\Transport\TransportInterface;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Extension\StubClientExtension;
use Nexus\Mcp\Tests\Fixtures\Client\Http\MirroringRecordingTransport;
use Nexus\Mcp\Tests\Fixtures\Client\Transport\SupervisableRecordingTransport;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\TestRequest;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(Client::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientTest extends AbstractMcpTestCase
{
    public function testConnectStartsTheTransportAndLogs(): void
    {
        $logger = new ArrayLogger();
        $client = (new ClientBuilder())->setLogger($logger)->setClientInfo('demo', '1.2.3')->build();
        $transport = new RecordingTransport();

        $client->connect($transport);

        self::assertTrue($transport->started);
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Starting MCP client.');
        self::assertCount(1, $matches);
        self::assertSame([], $matches[0]['context']);
    }

    public function testConnectTwiceThrowsClientAlreadyConnectedException(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $client->connect(new RecordingTransport());

        $this->expectException(ClientAlreadyConnectedException::class);
        $this->expectExceptionMessageMatches('/already connected/');

        $client->connect(new RecordingTransport());
    }

    public function testDisconnectClosesTheTransportAndAllowsReconnecting(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $first = new RecordingTransport();
        $client->connect($first);

        $client->disconnect();
        self::assertTrue($first->closed, 'disconnect() must close the attached transport.');

        $second = new RecordingTransport();
        $client->connect($second);
        self::assertTrue($second->started, 'disconnect() must detach the transport so a fresh connect() can run.');
    }

    public function testDisconnectForgetsTheServerItWasTalkingTo(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $first = new RecordingTransport();
        $client->connect($first);
        self::discover($client, $first, serverName: 'server-A');

        self::assertNotNull($client->getServerInfo());
        self::assertNotNull($client->getServerCapabilities());

        $client->disconnect();

        // Nothing has been discovered about the next server yet, so the accessors' documented
        // "null until discovery has run" is the only honest answer.
        self::assertNull($client->getServerInfo());
        self::assertNull($client->getServerCapabilities());

        $client->connect(new RecordingTransport());

        self::assertNull($client->getServerInfo());
        self::assertNull($client->getServerCapabilities());
    }

    public function testAReconnectedClientIsNotGatedByThePreviousServersAdvertisement(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $first = new RecordingTransport();
        $client->connect($first);
        // Server A advertises nothing, so every typed call is refused while it is attached.
        self::discover($client, $first, capabilities: []);

        $client->disconnect();
        $second = new RecordingTransport();
        $client->connect($second);

        // A refused call never sends or gets awaited, so bound the wait and mark it handled to keep a
        // regression on this test rather than the next one.
        $call = async(static fn(): CallToolResult|InputRequiredResult => $client->callTool('demo'))->ignore();
        $second->nextSend()->await(new TimeoutCancellation(1.0));

        self::assertCount(1, $second->sent);
        self::assertInstanceOf(CallToolRequest::class, $second->sent[0]['message']);

        $client->disconnect();

        $this->expectException(TransportAlreadyClosedException::class);
        $this->expectExceptionMessageIs('Cannot await-response on a closed transport.');

        $call->await();
    }

    public function testDisconnectIsANoOpWhenNotConnected(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();

        $client->disconnect();

        $this->expectNotToPerformAssertions();
    }

    public function testSendRequestBeforeConnectThrowsClientNotConnectedException(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $request = new ListToolsRequest(id: new RequestId(id: 1), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create()));

        $this->expectException(ClientNotConnectedException::class);
        $this->expectExceptionMessageMatches('/not connected/');

        $client->sendRequest($request, ListToolsResultResponse::class);
    }

    public function testSendRequestRegistersTheIdAndSendsTheRequestOnTheTransport(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())
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
        $client = (new ClientBuilder())->setLogger($logger)->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setLogger($logger)->setClientInfo('demo', '1.0.0')->build();
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
            // Bounded: without it, a client that never fails the caller reads as a hang, not a failure.
            $deferred->await(new TimeoutCancellation(1.0));
            self::fail('Expected the caller to be failed rather than left awaiting a response that cannot arrive.');
        } catch (OutboundRequestFailedException $e) {
            self::assertSame($error, $e);
        }

        self::assertCount(1, $logger->recordsMatching(LogLevel::ERROR, 'Transport error.'), 'The fault is still logged.');
    }

    public function testAFailedExchangeLeavesOtherRequestsPending(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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

    public function testARequestThatGoesUnansweredTimesOut(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->setRequestTimeout(0.05)->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        try {
            self::awaitPastDeadline(static fn(): DiscoverResult => $client->discover(), 0.05);
            self::fail('Expected the deadline to release the caller.');
        } catch (RequestTimeoutException $e) {
            self::assertSame('Request 1 went unanswered for 0.05 seconds.', $e->getMessage());
            self::assertSame(1, $e->requestId->id);
        }

        // The peer is told to stop working on a result nobody will read.
        self::assertCount(2, $transport->sent);
        $cancelled = $transport->sent[1]['message'];
        self::assertInstanceOf(CancelledNotification::class, $cancelled);
        self::assertSame(1, $cancelled->params->requestId->id);
        self::assertSame('The request timed out.', $cancelled->params->reason);
    }

    public function testATimedOutRequestReleasesItsCorrelationSlot(): void
    {
        $logger = new ArrayLogger();
        $client = (new ClientBuilder())->setLogger($logger)->setClientInfo('demo', '1.0.0')->setRequestTimeout(0.05)->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        try {
            self::awaitPastDeadline(static fn(): DiscoverResult => $client->discover(), 0.05);
        } catch (RequestTimeoutException) {
            // Expected: the assertion below is about what the timeout left behind.
        }

        $transport->emitMessage(self::discoverResponse(1));

        self::assertCount(
            1,
            $logger->recordsMatching(LogLevel::WARNING, 'Discarding orphan success response for unknown request id.'),
            'A response arriving after the timeout has no awaiter left to receive it.',
        );
    }

    public function testASettledRequestLeavesNoTimerArmed(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestTimeout(30.0)
            ->setMaxRequestTimeout(60.0)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $quiescent = EventLoop::getIdentifiers();

        $deferred = async(static fn(): DiscoverResult => $client->discover());
        $transport->nextSend()->await();
        $transport->emitMessage(self::discoverResponse(1));
        $deferred->await();

        // Both deadlines outlive the response by minutes, so leaving either armed would hold the event
        // loop open long after the client has nothing left to do.
        self::assertSame([], array_values(array_diff(EventLoop::getIdentifiers(), $quiescent)));
    }

    public function testATimeoutIsReportedEvenWhenTheCancellationCannotBeSent(): void
    {
        $logger = new ArrayLogger();
        $client = (new ClientBuilder())->setLogger($logger)->setClientInfo('demo', '1.0.0')->setRequestTimeout(0.05)->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn(): DiscoverResult => $client->discover());
        $transport->nextSend()->await();

        // The transport dies between the request going out and the deadline elapsing.
        $transport->sendError = new TransportAlreadyClosedException(operation: 'send');

        // A deadline is never the loop's work, so the loop needs work of its own to reach one.
        delay(0.1);

        try {
            $deferred->await();
            self::fail('Expected the timeout to surface even though the peer could not be told.');
        } catch (RequestTimeoutException $e) {
            self::assertSame(1, $e->requestId->id);
        }

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Could not tell the server that request {id} was abandoned.');
        self::assertCount(1, $matches);
        self::assertSame(1, $matches[0]['context']['id'] ?? null);
    }

    public function testATimeoutCanBeDisabled(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->setRequestTimeout(null)->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn(): DiscoverResult => $client->discover());
        $transport->nextSend()->await();

        delay(0.1);
        $transport->emitMessage(self::discoverResponse(1));

        self::assertInstanceOf(DiscoverResult::class, $deferred->await());
        self::assertCount(1, $transport->sent, 'No cancellation is sent for a request that was never abandoned.');
    }

    public function testSendRequestTimeoutOverridesTheClientDefault(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->setRequestTimeout(10.0)->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $request = new ListToolsRequest(id: new RequestId(id: 1), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create()));

        $this->expectException(RequestTimeoutException::class);
        $this->expectExceptionMessageIs('Request 1 went unanswered for 0.05 seconds.');

        self::awaitPastDeadline(
            static fn(): JsonRpcResultResponse => $client->sendRequest($request, ListToolsResultResponse::class, timeout: 0.05),
            0.05,
        );
    }

    public function testSendRequestTimeoutWidensTheCeilingItExceeds(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestTimeout(0.01)
            ->setMaxRequestTimeout(0.05)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $request = new ListToolsRequest(id: new RequestId(id: 1), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create()));

        // A ceiling shorter than the override would cut the caller short of the deadline it asked for.
        $this->expectException(RequestTimeoutException::class);
        $this->expectExceptionMessageIs('Request 1 went unanswered for 0.2 seconds.');

        self::awaitPastDeadline(
            static fn(): JsonRpcResultResponse => $client->sendRequest($request, ListToolsResultResponse::class, timeout: 0.2),
            0.2,
        );
    }

    /**
     * @param mixed $arguments Out-of-contract arguments, so the params constructor rejects them
     */
    public function testACallToolThatThrowsBeforeDispatchLeavesNoTimerArmed(mixed $arguments = [1, 2, 3]): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestTimeout(30.0)
            ->setMaxRequestTimeout(60.0)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        $quiescent = EventLoop::getIdentifiers();

        $fault = null;

        try {
            // @phpstan-ignore argument.type (an int-keyed list drives the runtime guard PHPStan rejects statically)
            $client->callTool('slow', $arguments, static fn(): null => null);
        } catch (\Throwable $e) {
            $fault = $e;
        }

        self::assertInstanceOf(\InvalidArgumentException::class, $fault, 'The params constructor rejects an int-keyed argument map.');

        // The deadline arms on construction, before the request is even built, so a throw in between must
        // still disarm it rather than hold the loop open for the ceiling.
        self::assertSame([], array_values(array_diff(EventLoop::getIdentifiers(), $quiescent)));
    }

    public function testProgressKeepsALongCallAlivePastTheIdleTimeout(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->setRequestTimeout(0.1)->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        $deferred = async(static fn(): CallToolResult|InputRequiredResult => $client->callTool('slow', null, static fn(): null => null));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(CallToolRequest::class, $request);
        $progressToken = $request->params->meta->progressToken;
        self::assertNotNull($progressToken);

        // Three quiet windows in a row, each shorter than the deadline but longer in sum.
        for ($tick = 0; $tick < 3; ++$tick) {
            delay(0.07);
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'method' => 'notifications/progress',
                'params' => ['progressToken' => $progressToken->token, 'progress' => (float) $tick],
            ]);
            delay(0.01);
        }

        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => $request->id->id, 'result' => ['content' => []]]);

        self::assertInstanceOf(CallToolResult::class, $deferred->await());
    }

    public function testTheCeilingAbandonsACallThatKeepsReportingProgress(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestTimeout(0.1)
            ->setMaxRequestTimeout(0.15)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        $deferred = async(static fn(): CallToolResult|InputRequiredResult => $client->callTool('endless', null, static fn(): null => null));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(CallToolRequest::class, $request);
        $progressToken = $request->params->meta->progressToken;
        self::assertNotNull($progressToken);

        for ($tick = 0; $tick < 4; ++$tick) {
            delay(0.06);
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'method' => 'notifications/progress',
                'params' => ['progressToken' => $progressToken->token, 'progress' => (float) $tick],
            ]);
        }

        try {
            $deferred->await();
            self::fail('Expected the ceiling to abandon the call however much progress arrived.');
        } catch (RequestTimeoutException $e) {
            self::assertSame('Request 2 went unanswered for 0.15 seconds.', $e->getMessage());
        }
    }

    public function testDiscoverBeforeConnectThrowsClientNotConnectedException(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();

        $this->expectException(ClientNotConnectedException::class);
        $this->expectExceptionMessageMatches('/not connected/');

        $client->discover();
    }

    public function testDiscoverSendsRequestAndCachesServerInfoAndCapabilities(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.2.3')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
            'error' => ['code' => -32_603, 'message' => 'peer refused'],
        ]);

        try {
            $deferred->await();
            self::fail('Expected RemoteCallFailedException.');
        } catch (RemoteCallFailedException $e) {
            self::assertSame('peer refused', $e->getMessage());
        }

        self::assertNull($client->getServerCapabilities(), 'A failed discover must not cache capabilities.');
    }

    public function testRetriesWithAVersionTheRejectionNamedAsSupported(): void
    {
        $logger = new ArrayLogger();
        $client = (new ClientBuilder())->setLogger($logger)->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->discover());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $first = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $first);

        $transport->emitMessage(self::unsupportedVersionResponse($first->id->id, [ProtocolVersion::LATEST_VERSION]));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $retry = $transport->sent[1]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $retry);
        self::assertNotSame($first->id->id, $retry->id->id, 'The retry claims a fresh request id.');
        self::assertSame(ProtocolVersion::LATEST_VERSION, $retry->params->meta->protocolVersion->version);

        $matches = $logger->recordsMatching(
            LogLevel::INFO,
            'Retrying request {id} as {retry}: the server does not support {requested}.',
        );
        self::assertCount(1, $matches);
        self::assertSame(
            ['id' => $first->id->id, 'retry' => $retry->id->id, 'requested' => 'Unsupported protocol version'],
            $matches[0]['context'],
        );

        $transport->emitMessage(self::discoverResponse($retry->id->id));

        $deferred->await();
    }

    public function testDoesNotRetryWhenTheRejectionNamesNoVersionThisSdkSpeaks(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->discover());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $first = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $first);

        $transport->emitMessage(self::unsupportedVersionResponse($first->id->id, ['1999-01-01']));

        try {
            $deferred->await();
            self::fail('Expected RemoteCallFailedException.');
        } catch (RemoteCallFailedException $e) {
            self::assertSame(ProtocolErrorCode::UnsupportedProtocolVersion->value, $e->getCode());
        }

        self::assertCount(1, $transport->sent, 'No mutually supported version means no retry.');
    }

    public function testTheRetryIsNotItselfRetried(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->discover());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $first = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $first);

        $transport->emitMessage(self::unsupportedVersionResponse($first->id->id, [ProtocolVersion::LATEST_VERSION]));
        $transport->nextSend()->await();
        self::assertCount(2, $transport->sent);
        $retry = $transport->sent[1]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $retry);

        $transport->emitMessage(self::unsupportedVersionResponse($retry->id->id, [ProtocolVersion::LATEST_VERSION]));

        try {
            $deferred->await();
            self::fail('Expected RemoteCallFailedException.');
        } catch (RemoteCallFailedException $e) {
            self::assertSame(ProtocolErrorCode::UnsupportedProtocolVersion->value, $e->getCode());
        }

        self::assertCount(2, $transport->sent, 'One retry, not a loop.');
    }

    public function testAnErrorThatIsNotAVersionRejectionIsNotRetried(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->discover());
        $transport->nextSend()->await();
        self::assertCount(1, $transport->sent);
        $first = $transport->sent[0]['message'];
        self::assertInstanceOf(DiscoverRequest::class, $first);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $first->id->id,
            'error' => ['code' => -32_603, 'message' => 'peer refused'],
        ]);

        try {
            $deferred->await();
            self::fail('Expected RemoteCallFailedException.');
        } catch (RemoteCallFailedException $e) {
            self::assertSame('peer refused', $e->getMessage());
        }

        self::assertCount(1, $transport->sent);
    }

    public function testDrainFiresFlushPendingOnTheDispatcher(): void
    {
        $logger = new ArrayLogger();
        $client = (new ClientBuilder())
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();

        self::assertNull($client->getServerInfo());
    }

    public function testGetServerInfoReturnsImplementationCachedFromDiscovery(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();

        self::assertNull($client->getServerCapabilities());
    }

    public function testGetServerCapabilitiesReturnsCapabilitiesCachedFromDiscovery(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport, capabilities: []);

        $this->expectException(ServerCapabilityNotSupportedException::class);
        $this->expectExceptionMessageIs(\sprintf(
            'Request method "%s" requires a server capability that was not advertised by server/discover.',
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        $deferred = async(static fn() => $client->readResource('example://greeting'));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(ReadResourceRequest::class, $request);
        self::assertSame('example://greeting', $request->params->uri);
        self::assertNull($request->params->inputResponses);
        self::assertNull($request->params->requestState);

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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        self::assertNull($request->params->inputResponses);
        self::assertNull($request->params->requestState);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['messages' => []],
        ]);

        $result = $deferred->await();
        self::assertInstanceOf(GetPromptResult::class, $result);
        self::assertSame([], $result->messages);
    }

    public function testReadResourceCarriesInputResponsesAndRequestStateBackToTheServer(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        $answer = new ElicitResult(action: ElicitAction::Accept, content: ['region' => 'eu']);

        $deferred = async(static fn() => $client->readResource(
            uri: 'example://greeting',
            inputResponses: ['pick_region' => $answer],
            requestState: 'opaque-state-from-the-server',
        ));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(ReadResourceRequest::class, $request);
        self::assertSame(['pick_region' => $answer], $request->params->inputResponses);
        self::assertSame('opaque-state-from-the-server', $request->params->requestState);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['contents' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
        ]);

        self::assertInstanceOf(ReadResourceResult::class, $deferred->await());
    }

    public function testGetPromptCarriesInputResponsesAndRequestStateBackToTheServer(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        $answer = new ElicitResult(action: ElicitAction::Decline);

        $deferred = async(static fn() => $client->getPrompt(
            name: 'walkthrough',
            inputResponses: ['confirm_audience' => $answer],
            requestState: 'opaque-state-from-the-server',
        ));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(GetPromptRequest::class, $request);
        self::assertNull($request->params->arguments);
        self::assertSame(['confirm_audience' => $answer], $request->params->inputResponses);
        self::assertSame('opaque-state-from-the-server', $request->params->requestState);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['messages' => []],
        ]);

        self::assertInstanceOf(GetPromptResult::class, $deferred->await());
    }

    public function testCompleteForwardsRefAndArgumentAndUnwrapsResult(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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

    public function testCallToolCarriesInputResponsesAndRequestStateBackToTheServer(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        $answer = new ElicitResult(action: ElicitAction::Accept, content: ['name' => 'Paul']);

        $deferred = async(static fn() => $client->callTool(
            name: 'greet',
            inputResponses: ['user_name' => $answer],
            requestState: 'opaque-state-from-the-server',
        ));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(CallToolRequest::class, $request);
        self::assertSame(['user_name' => $answer], $request->params->inputResponses);
        self::assertSame('opaque-state-from-the-server', $request->params->requestState);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['content' => []],
        ]);

        self::assertInstanceOf(CallToolResult::class, $deferred->await());
    }

    public function testCallToolCarriesInputResponsesAlongsideAProgressToken(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        $answer = new ElicitResult(action: ElicitAction::Decline);

        $deferred = async(static fn() => $client->callTool(
            name: 'greet',
            onProgress: static fn(): null => null,
            inputResponses: ['user_name' => $answer],
            requestState: 'state-with-progress',
        ));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(CallToolRequest::class, $request);
        self::assertSame(['user_name' => $answer], $request->params->inputResponses);
        self::assertSame('state-with-progress', $request->params->requestState);
        self::assertNotNull($request->params->meta->progressToken, 'The progress path must still mint a token.');

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['content' => []],
        ]);

        self::assertInstanceOf(CallToolResult::class, $deferred->await());
    }

    public function testCallToolOmitsInputResponsesAndRequestStateWhenNotAnswering(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport);

        $deferred = async(static fn() => $client->callTool('greet'));
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $request = $transport->sent[1]['message'];
        self::assertInstanceOf(CallToolRequest::class, $request);
        self::assertNull($request->params->inputResponses);
        self::assertNull($request->params->requestState);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $request->id->id,
            'result' => ['content' => []],
        ]);

        self::assertInstanceOf(CallToolResult::class, $deferred->await());
    }

    public function testCallToolWithProgressMintsTokenIntoMetaAndStreamsToCallback(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
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
        $client = (new ClientBuilder())
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
        $client = (new ClientBuilder())
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
        // Nothing cached means nothing to mirror. The server answers success here, so the
        // header-mismatch recovery never engages.
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport);

        self::callToolAndSettle($client, $transport, ['region' => 'us-west1']);

        self::assertSame([], self::lastContext($transport)->headers);
    }

    public function testCallToolRefreshesBindingsAndRetriesAfterAHeaderMismatch(): void
    {
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport);
        self::listToolsWithDeclarations($client, $transport);

        $call = async(static fn() => $client->callTool('good', ['region' => 'us-west1']));

        $transport->nextSend()->await();
        self::assertSame(['Mcp-Param-Region' => 'us-west1'], self::lastContext($transport)->headers);
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'error' => ['code' => ProtocolErrorCode::HeaderMismatch->value, 'message' => 'Header mismatch'],
        ]);

        // The re-listing renames the header, so the retry must carry the new one.
        $transport->nextSend()->await();
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'result' => [
                'tools' => [[
                    'name' => 'good',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Zone']],
                    ],
                ]],
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
        ]);

        $transport->nextSend()->await();
        self::assertSame(['Mcp-Param-Zone' => 'us-west1'], self::lastContext($transport)->headers);
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'result' => ['content' => [], 'resultType' => 'complete'],
        ]);

        self::assertInstanceOf(CallToolResult::class, $call->await());
    }

    public function testCallToolPropagatesAnErrorThatIsNotAHeaderMismatch(): void
    {
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport);
        self::listToolsWithDeclarations($client, $transport);

        $call = async(static fn() => $client->callTool('good', ['region' => 'us-west1']));

        $transport->nextSend()->await();
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'error' => ['code' => ProtocolErrorCode::InternalError->value, 'message' => 'Boom'],
        ]);

        try {
            $call->await();
            self::fail('The internal error should have propagated.');
        } catch (RemoteCallFailedException $e) {
            self::assertSame('Boom', $e->getMessage());
        }

        self::assertSame(1, self::countRequests($transport, CallToolRequest::class), 'Only a header mismatch triggers a retry.');
    }

    public function testCallToolRetriesOnlyOnceOnARepeatedHeaderMismatch(): void
    {
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport);
        self::listToolsWithDeclarations($client, $transport);

        $call = async(static fn() => $client->callTool('good', ['region' => 'us-west1']));

        foreach ([true, false] as $refreshes) {
            self::settleHeaderMismatch($transport);

            if (! $refreshes) {
                continue;
            }

            $transport->nextSend()->await();
            $transport->emitMessage([
                'jsonrpc' => '2.0',
                'id' => self::lastRequestId($transport),
                'result' => ['tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
            ]);
        }

        try {
            $call->await();
            self::fail('The repeated header mismatch should have propagated.');
        } catch (RemoteCallFailedException $e) {
            self::assertSame('Header mismatch', $e->getMessage());
        }

        self::assertSame(2, self::countRequests($transport, CallToolRequest::class), 'One call plus exactly one retry.');
    }

    public function testHeaderMismatchRefreshStopsAtThePageHoldingTheTool(): void
    {
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport);
        self::listToolsWithDeclarations($client, $transport);

        $call = async(static fn() => $client->callTool('good', ['region' => 'us-west1']));
        self::settleHeaderMismatch($transport);

        // The tool is on this page, which still advertises another. The walk must not fetch it.
        $transport->nextSend()->await();
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'result' => [
                'tools' => [self::toolListedUnder('good', 'Zone')],
                'nextCursor' => 'page-2',
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
        ]);

        self::settleToolCall($transport);
        $call->await();

        self::assertSame(2, self::countRequests($transport, ListToolsRequest::class), 'The opening listing plus one refresh page.');
    }

    public function testHeaderMismatchRefreshWalksToThePageHoldingTheTool(): void
    {
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport);
        self::listToolsWithDeclarations($client, $transport);

        $call = async(static fn() => $client->callTool('good', ['region' => 'us-west1']));
        self::settleHeaderMismatch($transport);

        $transport->nextSend()->await();
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'result' => [
                'tools' => [self::toolListedUnder('other', 'Other')],
                'nextCursor' => 'page-2',
                'ttlMs' => 0,
                'cacheScope' => 'private',
            ],
        ]);

        $transport->nextSend()->await();
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'result' => ['tools' => [self::toolListedUnder('good', 'Zone')], 'ttlMs' => 0, 'cacheScope' => 'private'],
        ]);

        $transport->nextSend()->await();
        self::assertSame(['Mcp-Param-Zone' => 'us-west1'], self::lastContext($transport)->headers);
        self::settleToolCall($transport, awaitSend: false);
        $call->await();

        self::assertSame(3, self::countRequests($transport, ListToolsRequest::class), 'The opening listing plus both refresh pages.');
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

    public function testRelistingAToolWhoseDeclarationsTurnedInvalidDropsItsBindings(): void
    {
        // The tool stays callable by name, so bindings the earlier listing cached would keep mirroring
        // headers for a tool this listing excludes.
        $transport = new MirroringRecordingTransport();
        $client = self::connectMirroring($transport);
        self::listToolsWithDeclarations($client, $transport);

        self::driveToolListing($client, $transport, [[
            'name' => 'good',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['region' => ['type' => 'number', 'x-mcp-header' => 'Region']],
            ],
        ]]);

        self::callToolAndSettle($client, $transport, ['region' => 'us-west1']);

        self::assertSame([], self::lastContext($transport)->headers, 'The declarations no longer hold, so nothing may be mirrored.');
    }

    public function testCallToolSendsNoHeadersOnATransportThatDoesNotMirror(): void
    {
        $transport = new RecordingTransport();
        $client = self::connectMirroring($transport);
        self::listToolsWithDeclarations($client, $transport);

        self::callToolAndSettle($client, $transport, ['region' => 'us-west1']);

        self::assertSame([], self::lastContext($transport)->headers);
    }

    public function testARequestCarryingNoTypedParamsIsNotRetried(): void
    {
        // `sendRequest()` takes any `JsonRpcRequest`, and a subclass may leave `params` null. Such a
        // request carries no `_meta` to restamp, so there is nothing to renegotiate.
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $request = new TestRequest(new RequestId(id: 'no-params'));
        $deferred = async(static fn() => $client->sendRequest($request, ListToolsResultResponse::class));
        $transport->nextSend()->await();

        $transport->emitMessage(self::unsupportedVersionResponse('no-params', [ProtocolVersion::LATEST_VERSION]));

        try {
            $deferred->await();
            self::fail('Expected RemoteCallFailedException.');
        } catch (RemoteCallFailedException $e) {
            self::assertSame(ProtocolErrorCode::UnsupportedProtocolVersion->value, $e->getCode());
        }

        self::assertCount(1, $transport->sent);
    }

    public function testAnExtensionOutboundMethodIsRefusedWhenTheServerDoesNotAdvertiseIt(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new StubClientExtension(
                identifier: 'com.example/feature',
                outboundRequests: [TestRequest::getMethod()],
            ))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport, capabilities: []);

        $this->expectException(ServerCapabilityNotSupportedException::class);
        $this->expectExceptionMessageIs(
            'Request method "tests/test-request" requires a server capability that was not advertised by server/discover.',
        );

        $client->sendRequest(new TestRequest(new RequestId(id: 51)), GenericResultResponse::class);
    }

    public function testAnExtensionOutboundMethodProceedsWhenTheServerAdvertisesIt(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new StubClientExtension(
                identifier: 'com.example/feature',
                outboundRequests: [TestRequest::getMethod()],
            ))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport, capabilities: ['extensions' => ['com.example/feature' => []]]);

        $deferred = async(static fn() => $client->sendRequest(new TestRequest(new RequestId(id: 52)), GenericResultResponse::class));
        $transport->nextSend()->await();
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => 52, 'result' => []]);

        self::assertInstanceOf(GenericResultResponse::class, $deferred->await());
    }

    public function testAnExtensionInboundMethodIsRefusedWhenTheServerDidNotAdvertiseIt(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new StubClientExtension(
                identifier: 'com.example/feature',
                requests: [TestRequest::getMethod() => TestRequest::class],
                requestHandlers: [TestRequest::getMethod() => new ClosureRequestHandler(
                    static fn(): EmptyResult => throw new \RuntimeException('The gate must reject before the handler runs.'),
                )],
            ))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport, capabilities: []);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => TestRequest::getMethod(),
        ]);
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $response = $transport->sent[1]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $response);
        self::assertSame(9, $response->id?->id);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $response->error->code);
    }

    public function testAnExtensionInboundMethodIsServedWhenTheServerAdvertisedIt(): void
    {
        $marker = new EmptyResult();
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new StubClientExtension(
                identifier: 'com.example/feature',
                requests: [TestRequest::getMethod() => TestRequest::class],
                requestHandlers: [TestRequest::getMethod() => new ClosureRequestHandler(static fn(): EmptyResult => $marker)],
            ))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);
        self::discover($client, $transport, capabilities: ['extensions' => ['com.example/feature' => []]]);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => TestRequest::getMethod(),
        ]);
        $transport->nextSend()->await();

        self::assertCount(2, $transport->sent);
        $response = $transport->sent[1]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $response);
        self::assertSame(9, $response->id->id);
        self::assertSame($marker, $response->result);
    }

    public function testAnExtensionOutboundMethodPassesUngatedBeforeDiscovery(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->enableExtension(new StubClientExtension(
                identifier: 'com.example/feature',
                outboundRequests: [TestRequest::getMethod()],
            ))
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $deferred = async(static fn() => $client->sendRequest(new TestRequest(new RequestId(id: 53)), GenericResultResponse::class));
        $transport->nextSend()->await();
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => 53, 'result' => []]);

        self::assertInstanceOf(GenericResultResponse::class, $deferred->await());
        self::assertCount(1, $transport->sent);
    }

    public function testListenSendsTheSubscriptionRequestAndReturnsImmediately(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});

        self::assertCount(1, $transport->sent);
        $request = $transport->sent[0]['message'];
        self::assertInstanceOf(SubscriptionsListenRequest::class, $request);
        self::assertSame('subscriptions/listen', $request::getMethod());
        self::assertSame($request->id->id, $stream->subscriptionId->id);
        self::assertTrue($request->params->notifications->toolsListChanged);
    }

    public function testListenRoutesTaggedNotificationsToItsOwnListener(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $seen = [];
        $stream = $client->listen(
            new SubscriptionFilter(toolsListChanged: true),
            static function (JsonRpcNotification $notification) use (&$seen): void {
                $seen[] = $notification::getMethod();
            },
        );

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
            'params' => ['_meta' => [NotificationMetaObject::SUBSCRIPTION_ID_KEY => $stream->subscriptionId->id]],
        ]);
        EventLoop::run();

        self::assertSame(['notifications/tools/list_changed'], $seen);
    }

    public function testAnUntaggedNotificationDoesNotReachASubscriptionListener(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $seen = [];
        $client->listen(new SubscriptionFilter(toolsListChanged: true), static function () use (&$seen): void {
            $seen[] = true;
        });

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
            'params' => [],
        ]);
        EventLoop::run();

        self::assertSame([], $seen, 'Only the stream that asked for a notification receives it.');
    }

    public function testClosingAStreamCancelsItAndStopsRoutingToIt(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $seen = [];
        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function () use (&$seen): void {
            $seen[] = true;
        });

        $stream->close();

        $cancellation = $transport->sent[1]['message'] ?? null;
        self::assertInstanceOf(CancelledNotification::class, $cancellation);
        self::assertSame($stream->subscriptionId->id, $cancellation->params->requestId->id);

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
            'params' => ['_meta' => [NotificationMetaObject::SUBSCRIPTION_ID_KEY => $stream->subscriptionId->id]],
        ]);
        EventLoop::run();

        self::assertSame([], $seen, 'A closed stream must stop receiving notifications.');
    }

    public function testListenBeforeConnectThrows(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();

        $this->expectException(ClientNotConnectedException::class);

        $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
    }

    public function testAFailedSendReleasesBothSubscriptionSlots(): void
    {
        $logger = new ArrayLogger();
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setLogger($logger)
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = new RecordingTransport();
        $transport->sendError = new \RuntimeException('pipe is gone');
        $client->connect($transport);

        $seen = [];

        $propagated = null;

        try {
            $client->listen(new SubscriptionFilter(toolsListChanged: true), static function () use (&$seen): void {
                $seen[] = true;
            });
        } catch (\RuntimeException $e) {
            $propagated = $e->getMessage();
        }

        // Asserted outside the try: PHPUnit's failure exceptions extend RuntimeException, so a fail()
        // inside it would be caught by the arm above and reported as the wrong thing.
        self::assertSame('pipe is gone', $propagated);

        // The correlation slot is free, so a late answer for that id reads as an orphan.
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => -32_603, 'message' => 'too late']]);

        // And the notification route is free, so nothing reaches the abandoned listener.
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
            'params' => ['_meta' => [NotificationMetaObject::SUBSCRIPTION_ID_KEY => 7]],
        ]);
        EventLoop::run();

        self::assertCount(1, $logger->recordsMatching(LogLevel::WARNING, 'Discarding orphan error response for unknown request id.'));
        self::assertSame([], $seen);
    }

    public function testClosingAStreamReleasesItsCorrelationSlot(): void
    {
        $logger = new ArrayLogger();
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setLogger($logger)
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
        $stream->close();

        // A server that answers anyway is answering a stream the client already retired.
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => -32_603, 'message' => 'too late']]);
        EventLoop::run();

        self::assertCount(1, $logger->recordsMatching(LogLevel::WARNING, 'Discarding orphan error response for unknown request id.'));
    }

    public function testARefusedSubscriptionDoesNotCrashTheLoopWhenNobodyAwaits(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
        $transport->emitMessage(['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => -32_603, 'message' => 'no subscriptions here']]);
        EventLoop::run();

        // Dropping the last reference destroys the future. An unconsumed error there reaches the loop as an
        // UnhandledFutureError and takes the whole run down.
        unset($stream);
        gc_collect_cycles();
        EventLoop::run();

        $this->expectNotToPerformAssertions();
    }

    public function testAServerEndedStreamReleasesItsNotificationRoute(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $seen = [];
        $client->listen(new SubscriptionFilter(toolsListChanged: true), static function () use (&$seen): void {
            $seen[] = true;
        });

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => ['_meta' => [SubscriptionsListenResultMetaObject::SUBSCRIPTION_ID_KEY => 7]],
        ]);
        EventLoop::run();

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
            'params' => ['_meta' => [NotificationMetaObject::SUBSCRIPTION_ID_KEY => 7]],
        ]);
        EventLoop::run();

        self::assertSame([], $seen, 'A stream the server ended must not keep routing to its callback.');
    }

    public function testClosingAStreamWhoseTransportIsGoneDoesNotThrow(): void
    {
        $logger = new ArrayLogger();
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setLogger($logger)
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});

        // Every real transport refuses a send once its peer is gone, which the recording double does not
        // model on its own.
        $transport->sendError = new TransportAlreadyClosedException(operation: 'send');

        // A `finally { $stream->close(); }` must not raise over the failure that caused the teardown.
        $stream->close();

        $matches = $logger->recordsMatching(LogLevel::DEBUG, 'Could not tell the server that subscription {id} was closed.');
        self::assertCount(1, $matches);
        self::assertSame(7, $matches[0]['context']['id'] ?? null);
        self::assertInstanceOf(TransportAlreadyClosedException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testAServerAnsweredStreamSendsNoCancellationOnClose(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => ['_meta' => [SubscriptionsListenResultMetaObject::SUBSCRIPTION_ID_KEY => 7]],
        ]);
        EventLoop::run();

        $stream->close();

        // The spec has a cancellation name a request that SHOULD still be in flight.
        self::assertCount(1, $transport->sent, 'A stream the server already answered has nothing left to cancel.');
    }

    public function testClosingAStreamAbortsItsExchange(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
        $stream->close();

        // Telling the server is not enough on HTTP: the POST carrying the stream keeps reading until
        // something stops it, and a `subscriptions/listen` never ends on its own.
        self::assertSame([7], $transport->aborted);
    }

    public function testAStreamTheServerAlreadyAnsweredIsNotAborted(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => ['_meta' => [SubscriptionsListenResultMetaObject::SUBSCRIPTION_ID_KEY => 7]],
        ]);
        EventLoop::run();

        $stream->close();

        self::assertSame([], $transport->aborted, 'An exchange the server ended has nothing left to stop.');
    }

    public function testATimedOutRequestAbortsItsExchange(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->setRequestTimeout(0.01)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        try {
            self::awaitPastDeadline(static fn(): ListToolsResult => $client->listTools(), 0.01);
            self::fail('Expected the deadline to abandon the request.');
        } catch (RequestTimeoutException) {
            // Asserted outside the try: PHPUnit's failure exceptions would otherwise be caught here.
        }

        self::assertSame([7], $transport->aborted);
    }

    public function testAwaitingAClosedStreamThrowsRatherThanBlocking(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = new RecordingTransport();
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
        $stream->close();

        $this->expectException(SubscriptionClosedException::class);
        $stream->await();
    }

    public function testARestartReopensTheStreamUnderTheSameSubscriptionId(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
        $id = $stream->subscriptionId->id;

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        $replayed = self::supervisedPeer($spawned, 1)->sent[0]['message'] ?? null;

        if (! $replayed instanceof SubscriptionsListenRequest) {
            self::fail('The replacement peer should have been sent the listen request again.');
        }

        // The subscription id is the request id, and the caller still holds it, so re-listening under a
        // fresh one would leave them naming a stream the server has never heard of.
        self::assertSame($id, $replayed->id->id);
        self::assertSame($id, $stream->subscriptionId->id);
        self::assertTrue($replayed->params->notifications->toolsListChanged);

        // A fresh peer knows nothing, so the replay has to carry the same self-describing `_meta` the
        // first send did.
        self::assertSame(ProtocolVersion::LATEST_VERSION, $replayed->params->meta->protocolVersion->version);
        self::assertSame('demo', $replayed->params->meta->clientInfo?->name);

        $transport->close();
    }

    public function testAReopenedStreamKeepsRoutingNotificationsToItsCallback(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $seen = [];
        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function () use (&$seen): void {
            $seen[] = true;
        });

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        // Asserted before driving a notification through: the route survives the peer death on its own, so
        // without this the callback would fire even if the re-open never happened.
        self::assertCount(1, self::supervisedPeer($spawned, 1)->sent);

        self::supervisedPeer($spawned, 1)->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
            'params' => ['_meta' => [NotificationMetaObject::SUBSCRIPTION_ID_KEY => $stream->subscriptionId->id]],
        ]);
        EventLoop::run();

        self::assertSame([true], $seen);

        $transport->close();
    }

    public function testALostPeerDoesNotSettleTheStreamWhileSupervisionContinues(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        // The replacement now owns the stream, so the answer that ends it arrives from there.
        self::supervisedPeer($spawned, 1)->emitMessage([
            'jsonrpc' => '2.0',
            'id' => $stream->subscriptionId->id,
            'result' => ['_meta' => [SubscriptionsListenResultMetaObject::SUBSCRIPTION_ID_KEY => $stream->subscriptionId->id]],
        ]);
        EventLoop::run();

        self::assertSame($stream->subscriptionId->id, $stream->await()->meta->subscriptionId->id);

        $transport->close();
    }

    public function testExhaustedSupervisionFailsEveryStreamStillOpen(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = self::supervisedTransport($spawned, maxRestarts: 1);
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});

        for ($i = 0; $i < 2; ++$i) {
            self::supervisedPeer($spawned, $i)->emitUnexpectedExit();
            EventLoop::run();
        }

        // No further peer is coming, so a stream still waiting would wait for the life of the process.
        $this->expectException(SupervisionExhaustedException::class);

        try {
            $stream->await();
        } finally {
            $transport->close();
        }
    }

    public function testDisconnectFailsEveryStreamStillOpen(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();

        $transport = self::supervisedTransport($spawned, restartDelay: 0.5);
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();

        // Let the loss actually reach the stream while the replacement is still pending. The stream
        // absorbs it by design and keeps waiting, so from here only the disconnect can end it. Without
        // this the absorption is still queued and the close alone settles the stream.
        delay(0.001);

        $client->disconnect();

        $this->expectException(TransportAlreadyClosedException::class);
        $stream->await();
    }

    public function testClosingTheTransportDirectlyEndsAStreamWaitingOnAReplacement(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = self::supervisedTransport($spawned, restartDelay: 0.5);
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();

        // Absorbed while the replacement is pending, exactly as designed.
        delay(0.001);

        $settledEarly = false;

        try {
            $stream->await();
            $settledEarly = true;
        } catch (RemoteCallFailedException|SubscriptionClosedException) {
            $settledEarly = true;
        } catch (\Throwable) {
            // A dry event loop, which is what "still waiting" looks like in an in-memory fixture.
        }

        self::assertFalse($settledEarly, 'The premise: the stream is still waiting on a replacement.');

        // Shutting the transport down without going through the client is a documented path, and it is
        // the last chance the stream has to hear anything.
        $transport->close();

        $settled = async(static fn(): mixed => $stream->await());

        // Referenced loop work, so a stream that never settles reads as still pending rather than as the
        // dry-loop error an in-memory fixture would otherwise produce.
        delay(0.05);

        self::assertTrue($settled->isComplete(), 'A close no replacement follows must end the stream.');

        $this->expectException(TransportAlreadyClosedException::class);
        $settled->await();
    }

    public function testClosingTheTransportDirectlyEndsARequestWaitingOnAReplacement(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRetryLostRequests(true)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned, restartDelay: 0.5);
        $client->connect($transport);

        $call = async(static fn(): ListToolsResult => $client->listTools());
        delay(0.001);

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        delay(0.001);

        // The premise: absorbed while the replacement is pending, so only the close can end it.
        self::assertFalse($call->isComplete());

        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);
        $call->await();
    }

    public function testDisconnectStopsAStreamFromBeingReopened(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});

        // Killed first, so a respawn is genuinely pending when the disconnect lands. Without that, no
        // re-open was possible either way and the assertion below would hold on its own.
        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        $client->disconnect();
        EventLoop::run();

        self::assertCount(1, $spawned, 'A disconnect cancels the pending respawn rather than re-opening into it.');
    }

    public function testAStreamSettledByADisconnectIsNotReplayedOnTheNextConnection(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $client->connect(self::supervisedTransport($spawned));

        $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
        $client->disconnect();

        $second = [];
        $transport = self::supervisedTransport($second);
        $client->connect($transport);

        self::supervisedPeer($second, 0)->emitUnexpectedExit();
        EventLoop::run();

        // The disconnect claimed every record, so the stream belonging to the retired connection cannot
        // reach a server that never served it.
        self::assertSame([], self::supervisedPeer($second, 1)->sent);

        $transport->close();
    }

    public function testARefusedSubscriptionEndsTheStreamEvenUnderSupervision(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});

        // A peer that answers "no" is answering. However replaceable it is, the stream is over, and a
        // caller waiting on it must not be left for the life of the process.
        self::supervisedPeer($spawned, 0)->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 7,
            'error' => ['code' => -32_601, 'message' => 'no subscriptions here'],
        ]);
        EventLoop::run();

        try {
            $stream->await();
            self::fail('Expected the refusal to reach the caller.');
        } catch (RemoteCallFailedException $e) {
            self::assertSame('no subscriptions here', $e->getMessage());
        } finally {
            $transport->close();
        }
    }

    public function testARefusedSubscriptionIsNotReplayedToTheReplacement(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
        self::supervisedPeer($spawned, 0)->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 7,
            'error' => ['code' => -32_601, 'message' => 'no subscriptions here'],
        ]);
        EventLoop::run();

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertSame([], self::supervisedPeer($spawned, 1)->sent, 'A stream the server refused is not the supervisor\'s to retry.');

        $transport->close();
    }

    public function testADuplicateSubscriptionIdLeavesTheLiveStreamIntact(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $seen = [];
        $first = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function () use (&$seen): void {
            $seen[] = true;
        });

        try {
            $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
            self::fail('Expected the colliding id to be refused.');
        } catch (DuplicateOutboundRequestIdException) {
            // Asserted outside the try: PHPUnit's failure exceptions would otherwise be caught here.
        }

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
            'params' => ['_meta' => [NotificationMetaObject::SUBSCRIPTION_ID_KEY => 7]],
        ]);
        EventLoop::run();

        self::assertSame([true], $seen, 'The refused second stream must not evict the first one\'s route.');
        self::assertSame(7, $first->subscriptionId->id);
    }

    public function testALostReadOnlyRequestIsSentAgainToTheReplacement(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->setRetryLostRequests(true)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $call = async(static fn(): ListToolsResult => $client->listTools());
        delay(0.001);

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        $replayed = self::supervisedPeer($spawned, 1)->sent[0]['message'] ?? null;

        if (! $replayed instanceof ListToolsRequest) {
            self::fail('The replacement peer should have been sent the lost request again.');
        }

        self::assertSame(7, $replayed->id->id);

        // Answered by the replacement under the original id, so the caller's await never noticed the peer
        // it started against had died.
        self::supervisedPeer($spawned, 1)->emitMessage([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => ['tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private'],
        ]);

        $result = $call->await();

        if (! $result instanceof ListToolsResult) {
            self::fail('The replacement peer\'s answer should have reached the original caller.');
        }

        self::assertSame([], $result->tools);

        $transport->close();
    }

    /**
     * @param \Closure(Client): mixed $call
     * @param non-empty-string        $expectedMethod
     */
    #[DataProvider('provideEveryRetryableMethodIsSentAgainCases')]
    public function testEveryRetryableMethodIsSentAgain(\Closure $call, string $expectedMethod): void
    {
        $spawned = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->setRetryLostRequests(true)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $pending = async(static fn(): mixed => $call($client));
        delay(0.001);

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        $replayed = self::supervisedPeer($spawned, 1)->sent[0]['message'] ?? null;

        if (! $replayed instanceof JsonRpcRequest) {
            self::fail(\sprintf('%s should have been sent again to the replacement peer.', $expectedMethod));
        }

        self::assertSame($expectedMethod, $replayed::getMethod());
        self::assertSame(7, $replayed->id->id);

        $pending->ignore();
        $transport->close();
    }

    /**
     * Every entry of the retryable-method allowlist.
     *
     * @return iterable<string, array{\Closure(Client): mixed, non-empty-string}>
     */
    public static function provideEveryRetryableMethodIsSentAgainCases(): iterable
    {
        yield 'server/discover' => [static fn(Client $c): mixed => $c->discover(), 'server/discover'];

        yield 'tools/list' => [static fn(Client $c): mixed => $c->listTools(), 'tools/list'];

        yield 'prompts/list' => [static fn(Client $c): mixed => $c->listPrompts(), 'prompts/list'];

        yield 'prompts/get' => [static fn(Client $c): mixed => $c->getPrompt('demo'), 'prompts/get'];

        yield 'resources/list' => [static fn(Client $c): mixed => $c->listResources(), 'resources/list'];

        yield 'resources/templates/list' => [
            static fn(Client $c): mixed => $c->listResourceTemplates(),
            'resources/templates/list',
        ];

        yield 'resources/read' => [static fn(Client $c): mixed => $c->readResource('file:///x'), 'resources/read'];

        yield 'completion/complete' => [
            static fn(Client $c): mixed => $c->complete(new PromptReference(name: 'demo'), ['name' => 'arg', 'value' => 'v']),
            'completion/complete',
        ];
    }

    /**
     * @param JsonRpcRequest<non-empty-string>    $request
     * @param class-string<JsonRpcResultResponse> $response
     */
    #[DataProvider('provideAnMrtrContinuationIsNotSentAgainCases')]
    public function testAnMrtrContinuationIsNotSentAgain(JsonRpcRequest $request, string $response): void
    {
        $spawned = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRetryLostRequests(true)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $call = async(static fn(): JsonRpcResultResponse => $client->sendRequest($request, $response));
        delay(0.001);

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        // Names a state-reading method, but resumes an exchange the dead peer suspended. Sending it again
        // hands a one-time answer over twice and quotes a token no replacement issued.
        self::assertSame([], self::supervisedPeer($spawned, 1)->sent);

        try {
            $call->await();
            self::fail('Expected the continuation to reach the caller rather than the replacement.');
        } catch (TransportAlreadyClosedException) {
            // Asserted outside the try, as above.
        }

        $transport->close();
    }

    /**
     * Both params shapes that carry continuation state, and each field on its own.
     *
     * @return iterable<string, array{JsonRpcRequest<non-empty-string>, class-string<JsonRpcResultResponse>}>
     */
    public static function provideAnMrtrContinuationIsNotSentAgainCases(): iterable
    {
        $answer = ['otp' => new ElicitResult(action: ElicitAction::Accept, content: ['code' => '839201'])];

        yield 'resources/read carrying answers' => [
            new ReadResourceRequest(
                id: new RequestId(id: 7),
                params: new ReadResourceRequestParams(
                    uri: 'file:///x',
                    meta: RequestMetaObjectFactory::create(),
                    inputResponses: $answer,
                ),
            ),
            ReadResourceResultResponse::class,
        ];

        yield 'resources/read carrying only a resume token' => [
            new ReadResourceRequest(
                id: new RequestId(id: 7),
                params: new ReadResourceRequestParams(
                    uri: 'file:///x',
                    meta: RequestMetaObjectFactory::create(),
                    requestState: 'resume-token',
                ),
            ),
            ReadResourceResultResponse::class,
        ];

        yield 'prompts/get carrying answers' => [
            new GetPromptRequest(
                id: new RequestId(id: 7),
                params: new GetPromptRequestParams(
                    name: 'demo',
                    meta: RequestMetaObjectFactory::create(),
                    inputResponses: $answer,
                ),
            ),
            GetPromptResultResponse::class,
        ];

        yield 'prompts/get carrying only a resume token' => [
            new GetPromptRequest(
                id: new RequestId(id: 7),
                params: new GetPromptRequestParams(
                    name: 'demo',
                    meta: RequestMetaObjectFactory::create(),
                    requestState: 'resume-token',
                ),
            ),
            GetPromptResultResponse::class,
        ];
    }

    public function testARetainedRequestFailsWhenANonReconnectingTransportCloses(): void
    {
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRetryLostRequests(true)
            ->build()
        ;
        $transport = new RecordingTransport();
        $client->connect($transport);

        $call = async(static fn(): ListToolsResult => $client->listTools());
        delay(0.001);

        // Nothing replaces a transport that cannot reconnect, so retention must not outlive its close.
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);
        $call->await();
    }

    public function testARequestOnAFreshTransportSurvivesTheOldOnesClose(): void
    {
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->setRetryLostRequests(true)->build();
        $client->connect(new RecordingTransport());
        $client->disconnect();

        $second = new RecordingTransport();
        $client->connect($second);

        async(static function () use ($second): void {
            // The send lands before the loop turns, so this only has to outlive the queued close decision.
            delay(0.001);
            $second->emitMessage(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => [], 'ttlMs' => 0, 'cacheScope' => 'private']]);
        });

        // Called from this fiber on purpose: the register and the send run before the loop turns, so the
        // retired transport's queued close decision lands while this request is in flight.
        $result = $client->listTools();

        self::assertSame([], $result->tools);

        $client->disconnect();
    }

    public function testALostToolCallIsNotSentAgain(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->setRetryLostRequests(true)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $call = async(static fn(): CallToolResult|InputRequiredResult => $client->callTool('acme_charge_card'));
        delay(0.001);

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        // A retry is at-least-once and the peer may have charged the card before it died. Asserted before
        // the await, which would otherwise report a re-sent call as a dry event loop rather than as this.
        self::assertSame([], self::supervisedPeer($spawned, 1)->sent);

        try {
            $call->await();
            self::fail('Expected the lost tool call to reach the caller.');
        } catch (TransportAlreadyClosedException) {
            // Asserted outside the try: PHPUnit's failure exceptions would otherwise be caught here.
        }

        $transport->close();
    }

    public function testALostRequestIsNotSentAgainWithoutTheOptIn(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $call = async(static fn(): ListToolsResult => $client->listTools());
        delay(0.001);

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertSame([], self::supervisedPeer($spawned, 1)->sent);

        try {
            $call->await();
            self::fail('Expected the lost request to reach the caller.');
        } catch (TransportAlreadyClosedException) {
            // Asserted outside the try, as above.
        }

        $transport->close();
    }

    public function testAHandAssembledClientDoesNotRetryLostRequests(): void
    {
        $outbound = new PendingOutboundRequests();
        $spawned = [];

        // The builder always passes the flag, so only a client assembled by hand exercises the default.
        $client = new Client(
            new Implementation(name: 'demo', version: '1.0.0'),
            new ClientCapabilities(),
            new ClientMessageDispatcher(
                new HandlerRegistry([], RequestHandlerInterface::class, 'Request handler'),
                new HandlerRegistry([], NotificationHandlerInterface::class, 'Notification handler'),
                $outbound,
            ),
            $outbound,
            static fn(): int => 7,
            static fn(): int => 1,
        );
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $call = async(static fn(): ListToolsResult => $client->listTools());
        delay(0.001);

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertSame([], self::supervisedPeer($spawned, 1)->sent);

        try {
            $call->await();
            self::fail('Expected the lost request to reach the caller.');
        } catch (TransportAlreadyClosedException) {
            // Asserted outside the try, as above.
        }

        $transport->close();
    }

    public function testARetriedRequestCarriesItsOriginalSendContext(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRetryLostRequests(true)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $context = new SendContext(relatedRequestId: new RequestId(id: 'parent-call'));
        $request = new ListToolsRequest(id: new RequestId(id: 7), params: new PaginatedRequestParams(meta: RequestMetaObjectFactory::create()));
        $call = async(static fn(): JsonRpcResultResponse => $client->sendRequest($request, ListToolsResultResponse::class, $context));
        delay(0.001);

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        // The routing metadata belongs to the request, not to the connection that first carried it.
        self::assertSame($context, self::supervisedPeer($spawned, 1)->sent[0]['context'] ?? null);

        $call->ignore();
        $transport->close();
    }

    public function testDisconnectFailsARequestWaitingOnAReplacement(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRetryLostRequests(true)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned, restartDelay: 0.5);
        $client->connect($transport);

        $call = async(static fn(): ListToolsResult => $client->listTools());
        delay(0.001);

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();

        // Absorbed while the replacement is pending, so from here only the disconnect can end it.
        delay(0.001);

        $client->disconnect();

        $this->expectException(TransportAlreadyClosedException::class);
        $call->await();
    }

    public function testARequestTheReplacementCannotTakeIsLeftForThePeerAfterIt(): void
    {
        $spawned = [];
        $logger = new ArrayLogger();
        $attempts = 0;
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setLogger($logger)
            ->setRetryLostRequests(true)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned, onSpawn: static function (SupervisableRecordingTransport $peer) use (&$attempts): void {
            if (2 !== ++$attempts) {
                return;
            }

            // Dies while taking the re-send, so a further replacement is already decided on by the time
            // the failure surfaces.
            $peer->onSend = static function (SupervisableRecordingTransport $dying): void {
                $dying->emitUnexpectedExit();
            };
            $peer->sendError = new TransportAlreadyClosedException(operation: 'send');
        });
        $client->connect($transport);

        // Two of them, so a walk that stopped at the first failure would leave the second unattempted.
        $first = async(static fn(): ListToolsResult => $client->listTools());
        $second = async(static fn(): ListPromptsResult => $client->listPrompts());
        delay(0.001);

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Could not send request {id} again to the replacement peer.');
        self::assertCount(2, $matches);
        self::assertSame([1, 2], [$matches[0]['context']['id'] ?? null, $matches[1]['context']['id'] ?? null]);

        // Still retained, so the peer after the one that died gets both.
        self::assertCount(2, self::supervisedPeer($spawned, 2)->sent);
        self::assertFalse($first->isComplete(), 'A request another peer will carry is not the caller\'s failure yet.');
        self::assertFalse($second->isComplete());

        $first->ignore();
        $second->ignore();
        $transport->close();
    }

    public function testALostRequestFailsWhenSupervisionGivesUp(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->setRetryLostRequests(true)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned, maxRestarts: 1);
        $client->connect($transport);

        $call = async(static fn(): ListToolsResult => $client->listTools());
        delay(0.001);

        for ($i = 0; $i < 2; ++$i) {
            self::supervisedPeer($spawned, $i)->emitUnexpectedExit();
            EventLoop::run();
        }

        // No further peer is coming, so the request has nothing left to be sent to.
        $this->expectException(SupervisionExhaustedException::class);

        try {
            $call->await();
        } finally {
            $transport->close();
        }
    }

    public function testALostRequestFailsWhenItCannotBeSentToTheReplacement(): void
    {
        $spawned = [];
        $attempts = 0;
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setRequestIdFactory(static fn(): int => 7)
            ->setRetryLostRequests(true)
            ->build()
        ;
        $transport = self::supervisedTransport($spawned, onSpawn: static function (SupervisableRecordingTransport $peer) use (&$attempts): void {
            if (2 === ++$attempts) {
                $peer->sendError = new TransportAlreadyClosedException(operation: 'send');
            }
        });
        $client->connect($transport);

        $call = async(static fn(): ListToolsResult => $client->listTools());
        delay(0.001);

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        // Nothing else will carry it, so the caller hears now rather than at the deadline.
        $this->expectException(TransportAlreadyClosedException::class);

        try {
            $call->await();
        } finally {
            $transport->close();
        }
    }

    public function testAThrowingReconnectListenerDoesNotStopTheReopen(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = self::supervisedTransport($spawned);

        // Registered before the client's, so it runs first and would abort the chain if it were unguarded.
        $transport->onReconnect(static function (): void {
            throw new \RuntimeException('listener blew up');
        });
        $transport->onError(static function (): void {});
        $client->connect($transport);

        $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertCount(1, self::supervisedPeer($spawned, 1)->sent);

        $transport->close();
    }

    public function testAStreamClosedBeforeARestartIsNotReopened(): void
    {
        $spawned = [];
        $client = (new ClientBuilder())->setClientInfo('demo', '1.0.0')->build();
        $transport = self::supervisedTransport($spawned);
        $client->connect($transport);

        $stream = $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});
        $stream->close();

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        self::assertSame([], self::supervisedPeer($spawned, 1)->sent, 'A stream the caller retired is not the supervisor\'s to restore.');

        $transport->close();
    }

    public function testAReopenThatCannotBeSentLeavesTheStreamForTheNextPeer(): void
    {
        $spawned = [];
        $logger = new ArrayLogger();
        $client = (new ClientBuilder())
            ->setClientInfo('demo', '1.0.0')
            ->setLogger($logger)
            ->setRequestIdFactory(static fn(): int => 7)
            ->build()
        ;
        $attempts = 0;
        $transport = self::supervisedTransport($spawned, onSpawn: static function (SupervisableRecordingTransport $peer) use (&$attempts): void {
            // The first replacement cannot take the re-open. The one after it can.
            if (2 === ++$attempts) {
                $peer->sendError = new TransportAlreadyClosedException(operation: 'send');
            }
        });
        $client->connect($transport);

        $client->listen(new SubscriptionFilter(toolsListChanged: true), static function (): void {});

        self::supervisedPeer($spawned, 0)->emitUnexpectedExit();
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Could not re-open subscription {id} against the replacement peer.');
        self::assertCount(1, $matches);
        self::assertSame(7, $matches[0]['context']['id'] ?? null);

        // Still registered, so the peer after this one gets another go at it.
        self::supervisedPeer($spawned, 1)->emitMessage(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed', 'params' => []]);
        self::supervisedPeer($spawned, 1)->emitUnexpectedExit();
        EventLoop::run();

        self::assertCount(1, self::supervisedPeer($spawned, 2)->sent);

        $transport->close();
    }

    /**
     * @param list<SupervisableRecordingTransport>                $spawned
     * @param null|\Closure(SupervisableRecordingTransport): void $onSpawn
     */
    private static function supervisedTransport(
        array &$spawned,
        int $maxRestarts = 3,
        ?\Closure $onSpawn = null,
        float $restartDelay = 0.0,
    ): SupervisedTransport {
        return new SupervisedTransport(
            static function () use (&$spawned, $onSpawn): SupervisableRecordingTransport {
                $peer = new SupervisableRecordingTransport();

                if (null !== $onSpawn) {
                    $onSpawn($peer);
                }

                $spawned[] = $peer;

                return $peer;
            },
            maxRestarts: $maxRestarts,
            restartDelay: $restartDelay,
        );
    }

    /**
     * @param list<SupervisableRecordingTransport> $spawned
     */
    private static function supervisedPeer(array $spawned, int $index): SupervisableRecordingTransport
    {
        $peer = $spawned[$index] ?? null;

        if (! $peer instanceof SupervisableRecordingTransport) {
            self::fail(\sprintf('Expected a spawned peer at index %d, got %d in all.', $index, \count($spawned)));
        }

        return $peer;
    }

    /**
     * @param list<string> $supported
     *
     * @return array<string, mixed>
     */
    private static function unsupportedVersionResponse(int|string $id, array $supported): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => ProtocolErrorCode::UnsupportedProtocolVersion->value,
                'message' => 'Unsupported protocol version',
                'data' => ['supported' => $supported, 'requested' => ProtocolVersion::LATEST_VERSION],
            ],
        ];
    }

    /**
     * Runs a call its deadline is expected to abandon, keeping the event loop busy until well past it. A
     * deadline is never the loop's own work, so reaching one takes a transport holding I/O open, which is
     * what a request in flight has and what these in-memory fixtures do not.
     *
     * @template TReturn
     *
     * @param \Closure(): TReturn $call
     *
     * @return TReturn
     */
    private static function awaitPastDeadline(\Closure $call, float $deadline): mixed
    {
        $future = async($call);

        delay($deadline * 2);

        return $future->await();
    }

    private static function connectMirroring(TransportInterface $transport, ?ArrayLogger $logger = null): Client
    {
        $client = (new ClientBuilder())
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
        return self::driveToolListing($client, $transport, [
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
        ]);
    }

    /**
     * Drives one `tools/list`, answering with the given tool definitions.
     *
     * @param list<array<string, mixed>> $tools
     */
    private static function driveToolListing(Client $client, MirroringRecordingTransport|RecordingTransport $transport, array $tools): ListToolsResult
    {
        $deferred = async(static fn(): ListToolsResult => $client->listTools());
        $transport->nextSend()->await();

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'result' => ['tools' => $tools, 'ttlMs' => 0, 'cacheScope' => 'private'],
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

    private static function settleHeaderMismatch(MirroringRecordingTransport|RecordingTransport $transport): void
    {
        $transport->nextSend()->await();
        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'error' => ['code' => ProtocolErrorCode::HeaderMismatch->value, 'message' => 'Header mismatch'],
        ]);
    }

    private static function settleToolCall(MirroringRecordingTransport|RecordingTransport $transport, bool $awaitSend = true): void
    {
        if ($awaitSend) {
            $transport->nextSend()->await();
        }

        $transport->emitMessage([
            'jsonrpc' => '2.0',
            'id' => self::lastRequestId($transport),
            'result' => ['content' => [], 'resultType' => 'complete'],
        ]);
    }

    /**
     * One `tools/list` entry for a tool whose single `region` argument mirrors into the given header.
     *
     * @return array<string, mixed>
     */
    private static function toolListedUnder(string $name, string $header): array
    {
        return [
            'name' => $name,
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => $header]],
            ],
        ];
    }

    /**
     * @param class-string $request
     */
    private static function countRequests(MirroringRecordingTransport|RecordingTransport $transport, string $request): int
    {
        $count = 0;

        foreach ($transport->sent as $entry) {
            if ($entry['message'] instanceof $request) {
                ++$count;
            }
        }

        return $count;
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
     * @return array{message: JsonRpcMessage, context: null|SendContext}
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
