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

namespace Nexus\Mcp\Tests\Server\Transport;

use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Enum\SdkErrorCode;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\UnknownProtocolError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Notification\SubscriptionsAcknowledgedNotification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\SubscriptionsAcknowledgedNotificationParams;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Server\Server;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Transport\Http\ResponseMode;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RequestIdLog;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LogLevel;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(StreamableHttpServerTransport::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class StreamableHttpServerTransportTest extends TestCase
{
    public function testNonPostReturns405WithAllowHeader(): void
    {
        $transport = self::makeTransport();
        $factory = new Psr17Factory();

        $response = $transport->handle($factory->createServerRequest('GET', 'https://mcp.test/'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
        self::assertSame('', (string) $response->getBody());
    }

    #[DataProvider('provideUndecodableBodyReturnsParseErrorCases')]
    public function testUndecodableBodyReturnsParseError(string $body): void
    {
        $response = self::makeTransport()->handle(self::makePost($body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::ParseError->value, self::errorPayload($response)['code'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUndecodableBodyReturnsParseErrorCases(): iterable
    {
        yield 'empty body' => [''];

        yield 'unterminated object' => ['{"jsonrpc":'];

        yield 'plain text' => ['not json at all'];
    }

    #[DataProvider('provideValidJsonThatIsNotAnObjectReturnsInvalidRequestCases')]
    public function testValidJsonThatIsNotAnObjectReturnsInvalidRequest(string $body): void
    {
        $response = self::makeTransport()->handle(self::makePost($body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, self::errorPayload($response)['code'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidJsonThatIsNotAnObjectReturnsInvalidRequestCases(): iterable
    {
        yield 'json number' => ['123'];

        yield 'json string' => ['"hello"'];

        yield 'json null' => ['null'];

        yield 'json array (removed batch)' => ['[{"jsonrpc":"2.0"}]'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('provideResponseShapedBodyIsRejectedCases')]
    public function testResponseShapedBodyIsRejected(array $body): void
    {
        // A response envelope is not a valid client-to-server message. The dispatcher discards it without
        // replying, so the transport must reject it rather than register a sink that never resolves.
        $response = self::makeTransport()->handle(self::makePost($body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, self::errorPayload($response)['code'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideResponseShapedBodyIsRejectedCases(): iterable
    {
        yield 'result response with an id' => [['jsonrpc' => '2.0', 'id' => 1, 'result' => []]];

        yield 'error response with an id' => [['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => 0, 'message' => 'boom']]];

        yield 'result response without an id' => [['jsonrpc' => '2.0', 'result' => []]];
    }

    #[DataProvider('providePresentButMalformedIdReturnsInvalidRequestCases')]
    public function testPresentButMalformedIdReturnsInvalidRequest(mixed $id): void
    {
        $response = self::makeTransport()->handle(self::makePost([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, self::errorPayload($response)['code'] ?? null);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function providePresentButMalformedIdReturnsInvalidRequestCases(): iterable
    {
        yield 'fractional number' => [1.5];

        yield 'null id' => [null];

        yield 'boolean id' => [true];

        yield 'empty string id' => [''];

        yield 'array id' => [[1]];
    }

    public function testTheValidatedTokenReachesHandlersOnTheReceiveContext(): void
    {
        $transport = self::makeTransport();
        $token = new VerifiedAccessToken(['https://mcp.test/'], ['files:read']);

        $contexts = [];
        $transport->onMessage(static function (array $envelope, ReceiveContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });

        $transport->handle(self::makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ])->withAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE, $token));

        self::assertCount(1, $contexts);
        self::assertSame($token, $contexts[0]->authInfo);
    }

    public function testTheValidatedTokenReachesHandlersOfABufferedRequest(): void
    {
        $transport = self::makeTransport(start: false);
        $token = new VerifiedAccessToken(['https://mcp.test/'], ['files:read']);

        $contexts = [];
        self::listen($transport);
        $transport->onMessage(static function (array $envelope, ReceiveContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });

        self::handle($transport, self::makePost([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::standardHeaders('server/discover'))->withAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE, $token));

        self::assertCount(1, $contexts);
        self::assertSame($token, $contexts[0]->authInfo);
    }

    public function testTheValidatedTokenReachesHandlersOfAStreamedRequest(): void
    {
        $transport = self::makeTransport(responseMode: ResponseMode::Sse, start: false);
        $token = new VerifiedAccessToken(['https://mcp.test/'], ['files:read']);

        $contexts = [];
        self::listen($transport, self::progressServer());
        $transport->onMessage(static function (array $envelope, ReceiveContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });

        self::handleAndRead(
            $transport,
            self::progressRequest(7)->withAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE, $token),
        );

        self::assertCount(1, $contexts);
        self::assertSame($token, $contexts[0]->authInfo);
    }

    public function testAnUnprotectedEndpointCarriesNoTokenOnTheReceiveContext(): void
    {
        $transport = self::makeTransport();

        $contexts = [];
        $transport->onMessage(static function (array $envelope, ReceiveContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });

        $transport->handle(self::makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ]));

        self::assertCount(1, $contexts);
        self::assertNull($contexts[0]->authInfo);
    }

    public function testAnAttributeThatIsNotATokenIsIgnored(): void
    {
        $transport = self::makeTransport();

        $contexts = [];
        $transport->onMessage(static function (array $envelope, ReceiveContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });

        $transport->handle(self::makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ])->withAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE, 'not-a-token'));

        self::assertCount(1, $contexts);
        self::assertNull($contexts[0]->authInfo);
    }

    public function testAListenRequestStreamsEvenUnderTheJsonResponseMode(): void
    {
        // A listen request is answered only when its stream ends, so the buffered path would hold the POST
        // open with nowhere to push the acknowledgement.
        $transport = self::makeTransport(responseMode: ResponseMode::Json);
        $transport->onMessage(static function (array $envelope) use ($transport): void {
            $id = $envelope['id'] ?? null;
            self::assertIsInt($id);
            $transport->send(
                new SubscriptionsAcknowledgedNotification(
                    params: new SubscriptionsAcknowledgedNotificationParams(notifications: new SubscriptionFilter()),
                ),
                new SendContext(relatedRequestId: new RequestId(id: $id)),
            );
        });

        $response = $transport->handle(self::makePost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'subscriptions/listen',
            'params' => ['notifications' => [], '_meta' => RequestMetaObjectFactory::shape()],
        ], self::standardHeaders('subscriptions/listen')));

        // The body stays open for the stream's lifetime, so the content type is what proves the carve-out.
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame(200, $response->getStatusCode());

        $transport->close();
    }

    public function testAClientCancellationNotificationIsAcceptedButNotDispatched(): void
    {
        // The spec makes closing the response stream the cancellation signal here, so this message is
        // neither required nor expected. Its `requestId` names the client's own id space, which no sink is
        // keyed by, so dispatching it would cancel whichever request holds that internal id.
        $logger = new ArrayLogger();
        $transport = self::makeTransport(logger: $logger);

        $received = [];
        $transport->onMessage(static function (array $envelope) use (&$received): void {
            $received[] = $envelope;
        });

        $response = $transport->handle(self::makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 1],
        ]));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame([], $received, 'A foreign id space must never reach the cancellation registry.');
        self::assertCount(
            1,
            $logger->recordsMatching(LogLevel::DEBUG, 'Ignoring a client cancellation notification: the response stream is the signal on this transport.'),
        );
    }

    public function testValidNotificationIsEmittedAndAcceptedWith202(): void
    {
        $transport = self::makeTransport();

        $received = [];
        $transport->onMessage(static function (array $envelope) use (&$received): void {
            $received[] = $envelope;
        });

        $response = $transport->handle(self::makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ]));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertCount(1, $received);
        self::assertSame('notifications/tools/list_changed', $received[0]['method'] ?? null);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('provideUnacceptableNotificationIsRejectedCases')]
    public function testUnacceptableNotificationIsRejected(array $body): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger);

        $emitted = false;
        $transport->onMessage(static function () use (&$emitted): void {
            $emitted = true;
        });

        $response = $transport->handle(self::makePost($body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, self::errorPayload($response)['code'] ?? null);
        // A rejected notification is never emitted to the dispatcher.
        self::assertFalse($emitted);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideUnacceptableNotificationIsRejectedCases(): iterable
    {
        yield 'missing method' => [['jsonrpc' => '2.0']];

        yield 'empty method' => [['jsonrpc' => '2.0', 'method' => '']];

        yield 'non-string method' => [['jsonrpc' => '2.0', 'method' => 123]];

        yield 'wrong jsonrpc version' => [['jsonrpc' => '1.0', 'method' => 'notifications/tools/list_changed']];
    }

    public function testPostRequestReturnsBufferedJsonResult(): void
    {
        $transport = self::makeTransport(start: false);
        self::listen($transport);

        $response = self::handle($transport, self::makePost([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::standardHeaders('server/discover')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = self::decode($response);
        self::assertSame('2.0', $body['jsonrpc'] ?? null);
        self::assertSame(7, $body['id'] ?? null);
        self::assertArrayHasKey('result', $body);
    }

    public function testStringClientIdIsRestoredOnTheResponse(): void
    {
        $transport = self::makeTransport(start: false);
        self::listen($transport);

        $response = self::handle($transport, self::makePost([
            'jsonrpc' => '2.0',
            'id' => 'req-abc',
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::standardHeaders('server/discover')));

        self::assertSame('req-abc', self::decode($response)['id'] ?? null);
    }

    public function testResponseBodyEncodesAnEmptyObjectSlotAsAnObjectNotAnArray(): void
    {
        $transport = self::makeTransport(start: false);
        self::listen($transport);

        $response = self::handle($transport, self::discoverPost());

        self::assertStringContainsString('"capabilities":{}', (string) $response->getBody());
    }

    public function testResponseBodyLeavesSlashesAndUnicodeUnescaped(): void
    {
        $transport = self::makeTransport(start: false);
        self::listen($transport);

        $response = self::handle($transport, self::makePost([
            'jsonrpc' => '2.0',
            'id' => 'scope/日本',
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::standardHeaders('server/discover')));

        $raw = (string) $response->getBody();
        self::assertStringContainsString('scope/日本', $raw);
        self::assertStringNotContainsString('scope\/', $raw);
    }

    public function testConcurrentRequestsSharingAClientIdDoNotCollide(): void
    {
        $transport = self::makeTransport(start: false);
        self::listen($transport);

        $post = self::makePost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::standardHeaders('server/discover'));

        // Both coroutines start on `async()`, so awaiting them one after the other still runs them concurrently.
        $firstPending = async(static fn(): ResponseInterface => $transport->handle($post));
        $secondPending = async(static fn(): ResponseInterface => $transport->handle(self::makePost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::standardHeaders('server/discover'))));

        $first = $firstPending->await();
        $second = $secondPending->await();
        self::assertInstanceOf(ResponseInterface::class, $first);
        self::assertInstanceOf(ResponseInterface::class, $second);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame(1, self::decode($first)['id'] ?? null);
        self::assertSame(1, self::decode($second)['id'] ?? null);
        // Re-keying to distinct internal ids keeps the shared client id from being rejected as a duplicate.
        self::assertArrayHasKey('result', self::decode($first));
        self::assertArrayHasKey('result', self::decode($second));
    }

    public function testInternalRequestIdsAscendFromOneOnTheStreamingPath(): void
    {
        // `Sse` answers with the stream straight away, so `handle()` returns without a dispatcher attached.
        $transport = self::makeTransport(responseMode: ResponseMode::Sse);
        $log = self::captureInternalIds($transport);

        $transport->handle(self::discoverPost());
        $transport->handle(self::discoverPost());
        $transport->handle(self::discoverPost());

        // The exact sequence is the contract: ids must climb, never restart, and never step backwards.
        self::assertSame([1, 2, 3], $log->ids);
    }

    public function testInternalRequestIdsAscendFromOneOnTheBufferedPath(): void
    {
        // Both dispatch paths mint from the one counter, so each needs its own sequence pinned.
        $transport = self::makeTransport(start: false);
        self::listen($transport);
        $log = self::captureInternalIds($transport);

        self::handle($transport, self::discoverPost());
        self::handle($transport, self::discoverPost());
        self::handle($transport, self::discoverPost());

        self::assertSame([1, 2, 3], $log->ids);
    }

    public function testAReleasedInternalIdIsNeverMintedAgain(): void
    {
        // An id must stay spoken for as long as its handler could still send a response, not merely as long
        // as its sink is registered. Recycling one routes an earlier client's response to a later client.
        $transport = self::makeTransport(responseMode: ResponseMode::Sse);
        $log = self::captureInternalIds($transport);

        $body = $transport->handle(self::discoverPost())->getBody();
        $body->close();                              // a client disconnect retires the sink
        $transport->handle(self::discoverPost());

        self::assertSame([1, 2], $log->ids, 'The retired id must not be handed to the next request.');
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    #[DataProvider('provideHeaderValidationFailureReturnsHeaderMismatchCases')]
    public function testHeaderValidationFailureReturnsHeaderMismatch(array $headers, array $body): void
    {
        // The mismatch is caught before dispatch, so no listener is attached.
        $response = self::makeTransport()->handle(self::makePost($body, $headers));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::HeaderMismatch->value, self::errorPayload($response)['code'] ?? null);

        $envelope = json_decode((string) $response->getBody(), true);
        self::assertIsArray($envelope);
        self::assertSame($body['id'] ?? null, $envelope['id'] ?? null, 'The recovered id must be echoed so the client can correlate the refusal.');
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, mixed>}>
     */
    public static function provideHeaderValidationFailureReturnsHeaderMismatchCases(): iterable
    {
        $discover = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => RequestMetaObjectFactory::shape()]];

        yield 'the protocol version header is absent' => [
            ['Mcp-Method' => 'server/discover'],
            $discover,
        ];

        yield 'the method header is absent' => [
            ['MCP-Protocol-Version' => ProtocolVersion::LATEST_VERSION],
            $discover,
        ];

        yield 'the method header disagrees with the body' => [
            ['MCP-Protocol-Version' => ProtocolVersion::LATEST_VERSION, 'Mcp-Method' => 'ping'],
            $discover,
        ];

        yield 'the name header disagrees with a tools/call body' => [
            self::standardHeaders('tools/call', 'wrong'),
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_weather', '_meta' => RequestMetaObjectFactory::shape()]],
        ];
    }

    public function testUnknownMethodRidesHttp404(): void
    {
        $transport = self::makeTransport(start: false);
        self::listen($transport);

        $response = self::handle($transport, self::makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'does/not/exist'],
            ['MCP-Protocol-Version' => ProtocolVersion::LATEST_VERSION, 'Mcp-Method' => 'does/not/exist'],
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, self::errorPayload($response)['code'] ?? null);
    }

    public function testEnvelopeLevelInvalidParamsRidesHttp400(): void
    {
        $transport = self::makeTransport(start: false);
        self::listen($transport);

        // An empty `_meta` fails request-params decoding inside the parser: an envelope-level -32602.
        $response = self::handle($transport, self::makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => []]],
            self::standardHeaders('server/discover'),
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidParams->value, self::errorPayload($response)['code'] ?? null);
    }

    public function testHandlerProducedProtocolErrorRidesHttp200(): void
    {
        // A handler that raises the same -32602 code as the envelope-level case above, but from execution,
        // so it rides HTTP 200 with the JSON-RPC error in the body.
        $server = new ServerBuilder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('server/discover', new ClosureRequestHandler(
                static fn() => throw new InvalidParamsException(null, 'invalid tool arguments'),
            ))
            ->build()
        ;

        $transport = self::makeTransport(start: false);
        self::listen($transport, $server);

        $response = self::handle($transport, self::makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => RequestMetaObjectFactory::shape()]],
            self::standardHeaders('server/discover'),
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidParams->value, self::errorPayload($response)['code'] ?? null);
    }

    public function testHandlerExceptionRidesHttp200AsInternalError(): void
    {
        $server = new ServerBuilder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('server/discover', new ClosureRequestHandler(
                static fn() => throw new \RuntimeException('handler boom'),
            ))
            ->build()
        ;

        $transport = self::makeTransport(start: false);
        self::listen($transport, $server);

        $response = self::handle($transport, self::makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => RequestMetaObjectFactory::shape()]],
            self::standardHeaders('server/discover'),
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InternalError->value, self::errorPayload($response)['code'] ?? null);
    }

    public function testRequestHandlerReceivesTheOriginatingHttpRequest(): void
    {
        $captured = null;
        $server = new ServerBuilder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('server/discover', new ClosureRequestHandler(
                static function (JsonRpcRequest $request, AbstractContext $context) use (&$captured): Result {
                    $captured = $context;

                    return new EmptyResult();
                },
            ))
            ->build()
        ;

        $transport = self::makeTransport(start: false);
        self::listen($transport, $server);

        $post = self::makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => RequestMetaObjectFactory::shape()]],
            self::standardHeaders('server/discover'),
        );

        self::handle($transport, $post);

        if (! $captured instanceof ServerContext) {
            self::fail('The request handler was not invoked with a ServerContext.');
        }

        self::assertSame($post, $captured->receiveContext->request);
    }

    public function testRequestBeforeStartIsRefusedWith503(): void
    {
        // Nothing is listening, so no dispatch would resolve the request. The endpoint must answer rather
        // than suspend on a response that cannot arrive.
        $response = self::makeTransport(start: false)->handle(self::discoverPost());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InternalError->value, self::errorPayload($response)['code'] ?? null);
        self::assertArrayNotHasKey('id', self::decode($response));
    }

    public function testRequestAfterCloseIsRefusedWith503(): void
    {
        $transport = self::makeTransport(start: false);
        self::listen($transport);
        $transport->close();

        $response = $transport->handle(self::discoverPost());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InternalError->value, self::errorPayload($response)['code'] ?? null);
    }

    public function testNotificationBeforeStartIsRefusedWith503(): void
    {
        // A 202 would tell the client the notification was accepted when nothing consumed it.
        $response = self::makeTransport(start: false)->handle(self::makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ]));

        self::assertSame(503, $response->getStatusCode());
    }

    public function testNotificationMethodSentAsRequestIsAnsweredRatherThanLeftPending(): void
    {
        // The dispatcher rejects the envelope. Were that rejection silent, this POST would never resolve.
        $transport = self::makeTransport(start: false);
        self::listen($transport);

        $response = self::handle($transport, self::makePost([
            'jsonrpc' => '2.0',
            'id' => 'n-1',
            'method' => 'notifications/tools/list_changed',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::standardHeaders('notifications/tools/list_changed')));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, self::errorPayload($response)['code'] ?? null);
        self::assertSame('n-1', self::decode($response)['id'] ?? null, 'The client id must be restored on the response.');
    }

    public function testAShedRequestCarriesServiceUnavailable(): void
    {
        // The one path where the in-flight cap meets the status resolver end to end.
        $transport = self::makeTransport(start: false);
        self::listen($transport, new ServerBuilder()
            ->setServerInfo('demo', '1.0.0')
            ->setMaxInFlightDispatches(1)
            ->replaceRequestHandler(
                DiscoverRequest::getMethod(),
                new ClosureRequestHandler(static function (): EmptyResult {
                    delay(0.05);

                    return new EmptyResult();
                }),
            )
            ->build());

        $occupied = async(static fn(): ResponseInterface => $transport->handle(self::discoverPost()));
        delay(0.01);
        $shed = $transport->handle(self::makePost([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::standardHeaders('server/discover')));
        $occupied->await();

        self::assertSame(503, $shed->getStatusCode());
        self::assertSame(SdkErrorCode::Overloaded->value, self::errorPayload($shed)['code'] ?? null);
    }

    public function testResponseCarryingANonSpecErrorCodeResolvesToBadRequest(): void
    {
        // Error::$code is a plain int, so a consumer-sent code outside ProtocolErrorCode must not strand
        // the awaiting request.
        $transport = self::makeTransport();
        $internalId = null;
        $transport->onMessage(static function (array $envelope) use (&$internalId): void {
            $internalId = $envelope['id'] ?? null;
        });

        $pending = async(static fn(): ResponseInterface => $transport->handle(self::discoverPost()));
        delay(0.01);

        self::assertIsInt($internalId);
        $transport->send(new JsonRpcErrorResponse(
            id: new RequestId(id: $internalId),
            error: new UnknownProtocolError(code: -32001, message: 'boom'),
        ));

        $response = $pending->await();
        self::assertInstanceOf(ResponseInterface::class, $response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32001, self::errorPayload($response)['code'] ?? null);
    }

    public function testNonPostIsAnsweredEvenWhenTheEndpointIsNotAccepting(): void
    {
        // The method check is pure HTTP and does not depend on the transport serving MCP traffic.
        $response = self::makeTransport(start: false)->handle(new Psr17Factory()->createServerRequest('GET', 'https://mcp.test/'));

        self::assertSame(405, $response->getStatusCode());
    }

    public function testSendBeforeStartThrows(): void
    {
        $this->expectException(TransportNotStartedException::class);

        self::makeTransport(start: false)->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));
    }

    public function testSendAfterCloseThrows(): void
    {
        $transport = self::makeTransport();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));
    }

    public function testSendDropsNotification(): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger);

        $transport->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));

        self::assertCount(1, $logger->recordsMatching(LogLevel::DEBUG, 'Dropping a notification with no related request to stream it to.'));
        self::assertCount(0, $logger->recordsMatching(LogLevel::WARNING, 'Dropping an unexpected server-initiated request.'));
    }

    public function testSendDropsServerInitiatedRequest(): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger);

        $transport->send(new DiscoverRequest(
            id: new RequestId(id: 1),
            params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()),
        ));

        self::assertCount(1, $logger->recordsMatching(LogLevel::WARNING, 'Dropping an unexpected server-initiated request.'));
    }

    public function testSendDropsResponseWithoutAnId(): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger);

        $transport->send(new JsonRpcErrorResponse(id: null, error: new InternalError(message: 'boom')));

        self::assertCount(1, $logger->recordsMatching(LogLevel::WARNING, 'Discarding a response that carries no id to correlate.'));
        self::assertCount(0, $logger->recordsMatching(LogLevel::WARNING, 'Dropping an unexpected server-initiated request.'));
    }

    #[DataProvider('provideSendDropsOrphanResponseCases')]
    public function testSendDropsOrphanResponse(int|string $id): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger);

        $transport->send(new JsonRpcErrorResponse(id: new RequestId(id: $id), error: new InternalError(message: 'boom')));

        self::assertCount(1, $logger->recordsMatching(LogLevel::WARNING, 'Discarding an orphan response with no in-flight request.'));
        self::assertCount(0, $logger->recordsMatching(LogLevel::WARNING, 'Dropping an unexpected server-initiated request.'));
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function provideSendDropsOrphanResponseCases(): iterable
    {
        yield 'string id never keys a sink' => ['req-abc'];

        yield 'integer id with no matching in-flight request' => [999];
    }

    public function testStartTwiceThrows(): void
    {
        $transport = self::makeTransport(start: false);
        $transport->start();

        $this->expectException(TransportAlreadyStartedException::class);

        $transport->start();
    }

    public function testStartAfterCloseThrows(): void
    {
        $transport = self::makeTransport(start: false);
        $transport->start();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->start();
    }

    public function testCloseDrainsBeforeSignallingCloseAndIsIdempotent(): void
    {
        $transport = self::makeTransport();

        $order = [];
        $transport->onDrain(static function () use (&$order): void {
            $order[] = 'drain';
        });
        $transport->onClose(static function () use (&$order): void {
            $order[] = 'close';
        });

        $transport->close();
        // A second close is a no-op: the listeners fire exactly once.
        $transport->close();

        self::assertSame(['drain', 'close'], $order);
    }

    public function testCloseSignalsCloseEvenWhenADrainListenerThrows(): void
    {
        $transport = self::makeTransport();

        $closed = false;
        $transport->onDrain(static function (): void {
            throw new \RuntimeException('drain boom');
        });
        $transport->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        try {
            $transport->close();
            self::fail('Expected the drain listener exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('drain boom', $e->getMessage());
        }

        // The close signal still fired, and the transport is closed despite the drain failure.
        self::assertTrue($closed);
        $this->expectException(TransportAlreadyClosedException::class);
        $transport->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));
    }

    #[DataProvider('provideRejectsInsufficientAcceptWith406Cases')]
    public function testRejectsInsufficientAcceptWith406(string $accept): void
    {
        $response = self::makeTransport()->handle(self::makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => RequestMetaObjectFactory::shape()]],
            ['Accept' => $accept],
        ));

        self::assertSame(406, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRejectsInsufficientAcceptWith406Cases(): iterable
    {
        yield 'accepts only application/json' => ['application/json'];

        yield 'accepts only text/event-stream' => ['text/event-stream'];

        yield 'accepts neither required type' => ['text/plain'];
    }

    public function testAcceptHeaderMatchesCaseInsensitively(): void
    {
        $transport = self::makeTransport(start: false);
        self::listen($transport);

        $response = self::handle($transport, self::makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => RequestMetaObjectFactory::shape()]],
            ['Accept' => 'Application/JSON, Text/Event-Stream'] + self::standardHeaders('server/discover'),
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('result', self::decode($response));
    }

    public function testSseModeStreamsProgressThenTheFinalResult(): void
    {
        $transport = self::makeTransport(responseMode: ResponseMode::Sse, start: false);
        self::listen($transport, self::progressServer());

        [$response, $body] = self::handleAndRead($transport, self::progressRequest(7));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame('keep-alive', $response->getHeaderLine('Connection'));
        self::assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));

        self::assertStringContainsString("event: message\ndata: ", $body);
        self::assertStringContainsString('notifications/progress', $body);
        self::assertStringContainsString('"id":7', $body);
        self::assertStringContainsString('"result"', $body);

        // Closing the fully-read body is a no-op: the stream already ended.
        $response->getBody()->close();
    }

    public function testSseStreamSignalsEndOfBodyAfterTheFinalResult(): void
    {
        $transport = self::makeTransport(responseMode: ResponseMode::Sse, keepAliveInterval: 0.02, start: false);
        self::listen($transport, self::progressServer());

        $eof = async(static function () use ($transport): string {
            $body = $transport->handle(self::progressRequest(7))->getBody();
            $body->read(65536); // drains the progress and final-result frames

            return $body->read(65536); // end-of-body once the stream has ended
        })->await();

        self::assertSame('', $eof);
    }

    public function testAutoModeUpgradesToSseWhenProgressArrives(): void
    {
        $transport = self::makeTransport(responseMode: ResponseMode::Auto, start: false);
        self::listen($transport, self::progressServer());

        [$response, $body] = self::handleAndRead($transport, self::progressRequest(7));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('notifications/progress', $body);
        self::assertStringContainsString('"result"', $body);
    }

    public function testJsonModeBuffersAndDropsProgress(): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger, ResponseMode::Json, start: false);
        self::listen($transport, self::progressServer());

        $response = self::handle($transport, self::progressRequest(7));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertArrayHasKey('result', self::decode($response));
        self::assertCount(1, $logger->recordsMatching(LogLevel::DEBUG, 'Dropping a notification: the JSON response mode cannot stream it.'));
    }

    public function testStreamEmitsKeepAliveFramesWhileTheHandlerIsBusy(): void
    {
        $transport = self::makeTransport(responseMode: ResponseMode::Sse, keepAliveInterval: 0.01, start: false);
        self::listen($transport, self::progressServer(busyFor: 0.03));

        [, $body] = self::handleAndRead($transport, self::progressRequest(7));

        self::assertStringContainsString(': keep-alive', $body);
        self::assertStringContainsString('"result"', $body);
    }

    public function testClosingTheBodyCancelsTheInFlightRequest(): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger, ResponseMode::Sse, start: false);
        self::listen($transport, self::progressServer());

        async(static function () use ($transport): void {
            $response = $transport->handle(self::progressRequest(7));
            // The client disconnects before consuming the stream.
            $response->getBody()->close();
            // Let the queued dispatch coroutine run: it is now cancelled.
            delay(0.01);
        })->await();

        self::assertSame(
            [],
            $logger->recordsMatching(LogLevel::WARNING, 'Discarding an orphan response with no in-flight request.'),
            'A disconnect cancels the request, so no response is produced for the transport to orphan.',
        );
        self::assertCount(1, $logger->recordsMatching(LogLevel::DEBUG, 'Dropping a notification for a request that is no longer in flight.'));
    }

    public function testAGracefullyEndedStreamDoesNotCancelAnything(): void
    {
        // `endStream()` retires the sink after the final frame, so the host disposing the body afterwards
        // must not be mistaken for the peer walking away.
        $transport = self::makeTransport(new ArrayLogger(), ResponseMode::Sse, start: false);
        self::listen($transport, self::progressServer());
        $cancelled = [];
        $transport->onCancel(static function (RequestId $id) use (&$cancelled): void {
            $cancelled[] = $id->id;
        });

        async(static function () use ($transport): void {
            $response = $transport->handle(self::progressRequest(7));
            delay(0.01);
            // The handler has answered by now, so this is the host tidying up a finished body.
            $response->getBody()->close();
        })->await();

        self::assertSame([], $cancelled);
    }

    public function testADisconnectReportsTheAbandonedRequestToItsListeners(): void
    {
        $transport = self::makeTransport(new ArrayLogger(), ResponseMode::Sse, start: false);
        self::listen($transport, self::progressServer(busyFor: 0.05));
        $cancelled = [];
        $subscription = $transport->onCancel(static function (RequestId $id) use (&$cancelled): void {
            $cancelled[] = $id->id;
        });

        async(static function () use ($transport): void {
            $transport->handle(self::progressRequest(7))->getBody()->close();
            delay(0.01);
        })->await();

        self::assertCount(1, $cancelled);
        $subscription->dispose();

        async(static function () use ($transport): void {
            $transport->handle(self::progressRequest(8))->getBody()->close();
            delay(0.01);
        })->await();

        self::assertCount(1, $cancelled, 'A disposed listener stops hearing about disconnects.');
    }

    public function testConstructorRejectsNonPositiveKeepAliveInterval(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^The SSE keep-alive interval must be positive, 0 given\.$/');

        new StreamableHttpServerTransport($factory, $factory, keepAliveInterval: 0.0);
    }

    /**
     * Records the transport-internal id of every request emitted to the dispatcher.
     */
    private static function captureInternalIds(StreamableHttpServerTransport $transport): RequestIdLog
    {
        $log = new RequestIdLog();
        $transport->onMessage(static function (array $envelope) use ($log): void {
            $id = $envelope['id'] ?? null;

            if (\is_int($id)) {
                $log->record($id);
            }
        });

        return $log;
    }

    /**
     * @param bool $start Whether to start the transport, which `handle()` requires. Pass `false` when the
     *                    caller attaches a server with `listen()`, which starts it.
     */
    private static function makeTransport(
        ?ArrayLogger $logger = null,
        ResponseMode $responseMode = ResponseMode::Auto,
        float $keepAliveInterval = 15.0,
        bool $start = true,
    ): StreamableHttpServerTransport {
        $factory = new Psr17Factory();
        $transport = new StreamableHttpServerTransport($factory, $factory, $logger ?? new ArrayLogger(), $responseMode, $keepAliveInterval);

        if ($start) {
            $transport->start();
        }

        return $transport;
    }

    private static function listen(StreamableHttpServerTransport $transport, ?Server $server = null): void
    {
        ($server ?? new ServerBuilder()->setServerInfo('demo', '1.0.0')->build())->listen($transport);
    }

    /**
     * A well-formed `server/discover` POST, headers included.
     */
    private static function discoverPost(): ServerRequestInterface
    {
        return self::makePost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::standardHeaders('server/discover'));
    }

    /**
     * Builds a POST, defaulting the required dual `Accept` header when the caller does not set one.
     *
     * @param array<string, mixed>|string $body
     * @param array<string, string>       $headers
     */
    private static function makePost(array|string $body, array $headers = []): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $raw = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);
        $request = $factory->createServerRequest('POST', 'https://mcp.test/')->withBody($factory->createStream($raw));

        $headers += ['Accept' => 'application/json, text/event-stream'];

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Drives a request whose response body must be read on the event loop (an SSE stream), returning the
     * response together with its fully read body.
     *
     * @return array{ResponseInterface, string}
     */
    private static function handleAndRead(StreamableHttpServerTransport $transport, ServerRequestInterface $request): array
    {
        $response = async(static fn(): ResponseInterface => $transport->handle($request))->await();
        self::assertInstanceOf(ResponseInterface::class, $response);

        // The SSE body is read on the loop: reading it drives the queued dispatch coroutine to completion.
        $body = async(static fn(): string => (string) $response->getBody())->await();
        self::assertIsString($body);

        return [$response, $body];
    }

    /**
     * A server whose `server/discover` handler reports one progress update, optionally staying busy after,
     * then returns an empty result.
     */
    private static function progressServer(float $busyFor = 0.0): Server
    {
        return new ServerBuilder()
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('server/discover', new ClosureRequestHandler(
                static function (JsonRpcRequest $request, AbstractContext $context) use ($busyFor): Result {
                    $context->reportProgress(0.5, 1.0, 'halfway');

                    if ($busyFor > 0.0) {
                        delay($busyFor);
                    }

                    return new EmptyResult();
                },
            ))
            ->build()
        ;
    }

    private static function progressRequest(int|string $id): ServerRequestInterface
    {
        return self::makePost([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape(progressToken: new ProgressToken('tok-1'))],
        ], self::standardHeaders('server/discover'));
    }

    /**
     * The standard request-metadata headers a conforming POST carries, matching the request bodies below.
     *
     * @return array<string, string>
     */
    private static function standardHeaders(string $method, ?string $name = null): array
    {
        $headers = [
            'MCP-Protocol-Version' => ProtocolVersion::LATEST_VERSION,
            'Mcp-Method' => $method,
        ];

        if (null !== $name) {
            $headers['Mcp-Name'] = $name;
        }

        return $headers;
    }

    private static function handle(StreamableHttpServerTransport $transport, ServerRequestInterface $request): ResponseInterface
    {
        $response = async(static fn(): ResponseInterface => $transport->handle($request))->await();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return $response;
    }

    /**
     * @return array<mixed, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<mixed, mixed>
     */
    private static function errorPayload(ResponseInterface $response): array
    {
        $body = self::decode($response);
        self::assertArrayHasKey('error', $body);
        $error = $body['error'];
        self::assertIsArray($error);

        return $error;
    }
}
