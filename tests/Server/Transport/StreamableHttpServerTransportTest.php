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
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Enum\SdkErrorCode;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\Error\UnknownProtocolError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\Notification\SubscriptionsAcknowledgedNotification;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\ProgressNotificationParams;
use Nexus\Mcp\Core\Schema\NotificationParams\SubscriptionsAcknowledgedNotificationParams;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\ResultResponse\CallToolResultResponse;
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Server\Server;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Transport\Http\ResponseMode;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RequestIdLog;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

use function Amp\async;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(StreamableHttpServerTransport::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class StreamableHttpServerTransportTest extends AbstractMcpTestCase
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

        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame(200, $response->getStatusCode());

        $transport->close();
    }

    public function testAClientCancellationNotificationIsAcceptedButNotDispatched(): void
    {
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
        self::assertArrayHasKey('result', self::decode($first));
        self::assertArrayHasKey('result', self::decode($second));
    }

    public function testInternalRequestIdsAscendFromOneOnTheStreamingPath(): void
    {
        $transport = self::makeTransport(responseMode: ResponseMode::Sse);
        $log = self::captureInternalIds($transport);

        $transport->handle(self::discoverPost());
        $transport->handle(self::discoverPost());
        $transport->handle(self::discoverPost());

        self::assertSame([1, 2, 3], $log->ids);
    }

    public function testInternalRequestIdsAscendFromOneOnTheBufferedPath(): void
    {
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
        $transport = self::makeTransport(responseMode: ResponseMode::Sse);
        $log = self::captureInternalIds($transport);

        $body = $transport->handle(self::discoverPost())->getBody();
        $body->close();
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

        $response = self::handle($transport, self::makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => []]],
            self::standardHeaders('server/discover'),
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidParams->value, self::errorPayload($response)['code'] ?? null);
    }

    public function testHandlerProducedProtocolErrorRidesHttp200(): void
    {
        $server = (new ServerBuilder())
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
        $server = (new ServerBuilder())
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
        $server = (new ServerBuilder())
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
        $response = self::makeTransport(start: false)->handle(self::makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ]));

        self::assertSame(503, $response->getStatusCode());
    }

    public function testNotificationMethodSentAsRequestIsAnsweredRatherThanLeftPending(): void
    {
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
        $transport = self::makeTransport(start: false);
        self::listen($transport, (new ServerBuilder())
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
            error: new UnknownProtocolError(code: -32_001, message: 'boom'),
        ));

        $response = $pending->await();
        self::assertInstanceOf(ResponseInterface::class, $response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32_001, self::errorPayload($response)['code'] ?? null);
    }

    public function testNonPostIsAnsweredEvenWhenTheEndpointIsNotAccepting(): void
    {
        $response = self::makeTransport(start: false)->handle((new Psr17Factory())->createServerRequest('GET', 'https://mcp.test/'));

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

    #[DataProvider('provideAThrowingListenerUnwindsItsSinkCases')]
    public function testAThrowingListenerUnwindsItsSink(ResponseMode $responseMode): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger, $responseMode);
        $transport->onMessage(static function (): void {
            throw new \RuntimeException('listener blew up');
        });

        try {
            self::handle($transport, self::discoverPost());
            self::fail('The transport must not swallow a listener throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('listener blew up', $e->getMessage());
        }

        $transport->send(new JsonRpcErrorResponse(id: new RequestId(id: 1), error: new InternalError(message: 'boom')));

        self::assertCount(
            1,
            $logger->recordsMatching(LogLevel::WARNING, 'Discarding an orphan response with no in-flight request.'),
            'A sink left registered by a throwing listener would route this response instead of orphaning it.',
        );
    }

    /**
     * @return iterable<string, array{ResponseMode}>
     */
    public static function provideAThrowingListenerUnwindsItsSinkCases(): iterable
    {
        yield 'buffered' => [ResponseMode::Json];

        yield 'streaming' => [ResponseMode::Sse];
    }

    public function testABufferedResponseJsonCannotEncodeIsAnsweredWithAnInternalError(): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger, ResponseMode::Json);
        $transport->onMessage(static function (array $envelope) use ($transport): void {
            $transport->send(self::unencodableResponse($envelope));
        });

        $response = self::handle($transport, self::discoverPost('req-abc'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('req-abc', self::decode($response)['id'] ?? null, 'The error echoes the id the client sent, not the transport-internal one.');
        self::assertSame(ProtocolErrorCode::InternalError->value, self::errorPayload($response)['code'] ?? null);
        self::assertSame('The response could not be encoded.', self::errorPayload($response)['message'] ?? null);

        $records = $logger->recordsMatching(LogLevel::ERROR, 'Replacing a response JSON cannot encode with an internal error: {reason}.');
        self::assertCount(1, $records);
        self::assertSame(['reason' => 'Malformed UTF-8 characters, possibly incorrectly encoded'], $records[0]['context']);
    }

    public function testAStreamedResponseJsonCannotEncodeIsFramedAsAnInternalError(): void
    {
        $transport = self::makeTransport(responseMode: ResponseMode::Sse, keepAliveInterval: 0.01);
        $transport->onMessage(static function (array $envelope) use ($transport): void {
            $transport->send(self::unencodableResponse($envelope));
        });

        $body = $transport->handle(self::discoverPost('req-abc'))->getBody();
        $frame = $body->read(8_192);

        self::assertSame(
            "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":\"req-abc\",\"error\":{\"code\":-32603,\"message\":\"The response could not be encoded.\"}}\n\n",
            $frame,
        );
        self::assertTrue($body->eof(), 'The substituted error still retires the stream, so the consumer reaches end-of-body.');
    }

    public function testANotificationJsonCannotEncodeLeavesTheRequestAnswerable(): void
    {
        $transport = self::makeTransport();
        $transport->onMessage(static function (array $envelope) use ($transport): void {
            $id = $envelope['id'] ?? null;
            self::assertIsInt($id);
            $related = new SendContext(relatedRequestId: new RequestId(id: $id));

            try {
                $transport->send(self::unencodableProgress(), $related);
                self::fail('The transport must not swallow a notification JSON cannot encode.');
            } catch (\JsonException $e) {
                self::assertSame('Malformed UTF-8 characters, possibly incorrectly encoded', $e->getMessage());
            }

            $transport->send(new GenericResultResponse(id: new RequestId(id: $id), result: new EmptyResult()), $related);
        });

        $response = self::handle($transport, self::discoverPost());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertArrayHasKey('result', self::decode($response));
    }

    public function testACloseRacingAResponseBeingWrittenDoesNotSettleItTwice(): void
    {
        $streamFactory = new class implements StreamFactoryInterface {
            public ?StreamableHttpServerTransport $transport = null;
            public int $closes = 0;
            private readonly Psr17Factory $delegate;

            public function __construct()
            {
                $this->delegate = new Psr17Factory();
            }

            #[\Override]
            public function createStream(string $content = ''): StreamInterface
            {
                if (null !== $this->transport) {
                    ++$this->closes;
                    $this->transport->close();
                }

                return $this->delegate->createStream($content);
            }

            #[\Override]
            public function createStreamFromFile(string $filename, string $mode = 'r'): never
            {
                throw new \BadMethodCallException('unused');
            }

            /**
             * @param resource $resource
             */
            #[\Override]
            public function createStreamFromResource($resource): never
            {
                throw new \BadMethodCallException('unused');
            }
        };

        $transport = new StreamableHttpServerTransport(new Psr17Factory(), $streamFactory, new ArrayLogger(), ResponseMode::Json);
        $streamFactory->transport = $transport;
        $transport->start();
        $transport->onMessage(static function (array $envelope) use ($transport): void {
            $id = $envelope['id'] ?? null;
            self::assertIsInt($id);
            $transport->send(new GenericResultResponse(id: new RequestId(id: $id), result: new EmptyResult()));
        });

        $response = self::handle($transport, self::discoverPost());

        self::assertSame(1, $streamFactory->closes, 'The close must land while the response body is being built.');
        self::assertSame(
            200,
            $response->getStatusCode(),
            'The sink is retired before the body is built, so a close in between cannot settle it first.',
        );
    }

    public function testAnUpgradeTheTransportCannotBuildLeavesTheRequestBuffered(): void
    {
        $transport = new StreamableHttpServerTransport(self::factoryFailingOnce(), new Psr17Factory(), new ArrayLogger());
        $transport->start();
        $transport->onMessage(static function (array $envelope) use ($transport): void {
            $id = $envelope['id'] ?? null;
            self::assertIsInt($id);
            $related = new SendContext(relatedRequestId: new RequestId(id: $id));

            try {
                $transport->send(new ProgressNotification(params: new ProgressNotificationParams(
                    progressToken: new ProgressToken('tok-1'),
                    progress: 0.5,
                )), $related);
                self::fail('The transport must not swallow a response factory failure.');
            } catch (\RuntimeException $e) {
                self::assertSame('response factory is broken', $e->getMessage());
            }

            $transport->send(new GenericResultResponse(id: new RequestId(id: $id), result: new EmptyResult()), $related);
        });

        $response = self::handle($transport, self::discoverPost());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'), 'A stream that was never handed back leaves the request buffered.');
        self::assertArrayHasKey('result', self::decode($response));
    }

    public function testAStreamTheTransportCannotBuildRegistersNoSink(): void
    {
        $logger = new ArrayLogger();
        $transport = new StreamableHttpServerTransport(self::factoryFailingOnce(), new Psr17Factory(), $logger, ResponseMode::Sse);
        $transport->start();
        $transport->onMessage(static function (): void {});

        try {
            $transport->handle(self::discoverPost());
            self::fail('The transport must not swallow a response factory failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('response factory is broken', $e->getMessage());
        }

        $transport->send(new JsonRpcErrorResponse(id: new RequestId(id: 1), error: new InternalError(message: 'boom')));

        self::assertCount(
            1,
            $logger->recordsMatching(LogLevel::WARNING, 'Discarding an orphan response with no in-flight request.'),
            'A sink registered before its response was built would route this into a stream nobody holds.',
        );
    }

    public function testAResponseTheTransportCannotBuildFailsTheRequest(): void
    {
        $responseFactory = new class implements ResponseFactoryInterface {
            #[\Override]
            public function createResponse(int $code = 200, string $reasonPhrase = ''): never
            {
                throw new \RuntimeException('response factory is broken');
            }
        };

        $transport = new StreamableHttpServerTransport($responseFactory, new Psr17Factory(), new ArrayLogger(), ResponseMode::Json);
        $transport->start();
        $transport->onMessage(static function (array $envelope) use ($transport): void {
            async(static function () use ($transport, $envelope): void {
                $id = $envelope['id'] ?? null;
                self::assertIsInt($id);

                try {
                    $transport->send(new GenericResultResponse(id: new RequestId(id: $id), result: new EmptyResult()));
                } catch (\RuntimeException $e) {
                    self::assertSame('response factory is broken', $e->getMessage());
                }
            })->ignore();
        });

        try {
            self::handle($transport, self::discoverPost());
            self::fail('Expected the response factory failure to reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('response factory is broken', $e->getMessage(), 'A response that cannot be built fails the request instead of parking it forever.');
        }
    }

    public function testASinkThatCannotBeAnsweredCostsOnlyItself(): void
    {
        $transport = new StreamableHttpServerTransport(self::factoryFailingOnce(), new Psr17Factory(), new ArrayLogger(), ResponseMode::Json);
        $transport->start();
        $transport->onMessage(static function (): void {});

        $closed = false;
        $transport->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        $first = async(static fn(): ResponseInterface => $transport->handle(self::discoverPost()));
        $second = async(static fn(): ResponseInterface => $transport->handle(self::discoverPost('req-2')));
        delay(0.0);

        try {
            $transport->close();
            self::fail('Expected the response factory failure to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('response factory is broken', $e->getMessage());
        }

        try {
            $first->await();
            self::fail('Expected the unanswerable request to fail rather than park.');
        } catch (\RuntimeException $e) {
            self::assertSame('response factory is broken', $e->getMessage(), 'A sink whose error cannot be built fails, instead of hanging forever.');
        }

        $response = $second->await();
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(503, $response->getStatusCode(), 'A sink retired after the failing one must still be answered.');
        self::assertTrue($closed, 'A sink that cannot be answered must not cost the transport its close signal.');
    }

    public function testAThrowingLoggerCannotStrandTheSubstitutedResponse(): void
    {
        $factory = new Psr17Factory();
        $logger = new class extends AbstractLogger {
            /**
             * @param array<array-key, mixed> $context
             */
            #[\Override]
            public function log(mixed $level, string|\Stringable $message, array $context = []): never
            {
                throw new \RuntimeException('logger is broken');
            }
        };

        $transport = new StreamableHttpServerTransport($factory, $factory, $logger, ResponseMode::Json);
        $transport->start();
        $transport->onMessage(static function (array $envelope) use ($transport): void {
            async(static function () use ($transport, $envelope): void {
                try {
                    $transport->send(self::unencodableResponse($envelope));
                } catch (\RuntimeException $e) {
                    self::assertSame('logger is broken', $e->getMessage());
                }
            })->ignore();
        });

        $response = self::handle($transport, self::discoverPost());

        self::assertSame(500, $response->getStatusCode(), 'The substitute is written even when reporting the failure throws.');
    }

    public function testACloseRacingAnUpgradeLeavesTheRequestSettledOnce(): void
    {
        $responseFactory = new class implements ResponseFactoryInterface {
            public ?StreamableHttpServerTransport $transport = null;
            public int $closes = 0;
            private readonly Psr17Factory $delegate;

            public function __construct()
            {
                $this->delegate = new Psr17Factory();
            }

            #[\Override]
            public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
            {
                if (null !== $this->transport && 0 === $this->closes) {
                    ++$this->closes;
                    $this->transport->close();
                }

                return $this->delegate->createResponse($code, $reasonPhrase);
            }
        };

        $transport = new StreamableHttpServerTransport($responseFactory, new Psr17Factory(), new ArrayLogger());
        $responseFactory->transport = $transport;
        $transport->start();
        $transport->onMessage(static function (array $envelope) use ($transport): void {
            $id = $envelope['id'] ?? null;
            self::assertIsInt($id);

            $transport->send(new ProgressNotification(params: new ProgressNotificationParams(
                progressToken: new ProgressToken('tok-1'),
                progress: 0.5,
            )), new SendContext(relatedRequestId: new RequestId(id: $id)));
        });

        $response = self::handle($transport, self::discoverPost());

        self::assertSame(1, $responseFactory->closes, 'The close must land while the SSE response is being built.');
        self::assertSame(503, $response->getStatusCode(), 'A close that settled the request leaves the upgrade nothing to do.');
    }

    public function testCloseRetiresEveryRequestStillInFlight(): void
    {
        $transport = self::makeTransport(responseMode: ResponseMode::Json, keepAliveInterval: 0.01);
        $transport->onMessage(static function (): void {});

        $cancelled = [];
        $transport->onCancel(static function (RequestId $id) use (&$cancelled): void {
            $cancelled[] = $id->id;
        });

        $streamed = $transport->handle(self::listenPost())->getBody();
        $buffered = async(static fn(): ResponseInterface => $transport->handle(self::discoverPost()));
        delay(0.0);

        $transport->close();

        self::assertSame('', $streamed->read(8_192), 'An ended stream reads empty instead of yielding another keep-alive.');
        self::assertTrue($streamed->eof());

        $streamed->close();
        self::assertSame([], $cancelled, 'A stream retired at close is no longer registered, so closing its body reports no cancellation.');

        $response = $buffered->await();
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(1, self::decode($response)['id'] ?? null, 'The error echoes the id the client sent, not the transport-internal one.');
        self::assertSame(ProtocolErrorCode::InternalError->value, self::errorPayload($response)['code'] ?? null);
        self::assertSame('The MCP endpoint is shutting down.', self::errorPayload($response)['message'] ?? null);
    }

    /**
     * @param int|non-empty-string $id
     */
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
        $transport->close();

        self::assertSame(['drain', 'close'], $order);
    }

    public function testADrainListenerReenteringCloseFiresListenersOnce(): void
    {
        $transport = self::makeTransport();

        $order = [];
        $transport->onDrain(static function () use (&$order, $transport): void {
            $order[] = 'drain';
            $transport->close();
        });
        $transport->onClose(static function () use (&$order): void {
            $order[] = 'close';
        });

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

        $response->getBody()->close();
    }

    public function testSseStreamSignalsEndOfBodyAfterTheFinalResult(): void
    {
        $transport = self::makeTransport(responseMode: ResponseMode::Sse, keepAliveInterval: 0.02, start: false);
        self::listen($transport, self::progressServer());

        $eof = async(static function () use ($transport): string {
            $body = $transport->handle(self::progressRequest(7))->getBody();
            $body->read(65_536);

            return $body->read(65_536);
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
            $response->getBody()->close();
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
        $transport = self::makeTransport(new ArrayLogger(), ResponseMode::Sse, start: false);
        self::listen($transport, self::progressServer());
        $cancelled = [];
        $transport->onCancel(static function (RequestId $id) use (&$cancelled): void {
            $cancelled[] = $id->id;
        });

        async(static function () use ($transport): void {
            $response = $transport->handle(self::progressRequest(7));
            delay(0.01);
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

    public function testADisconnectOnAnUpgradedStreamReportsTheAbandonedRequest(): void
    {
        $transport = self::makeTransport(new ArrayLogger(), ResponseMode::Auto, start: false);
        self::listen($transport, self::progressServer(busyFor: 0.05));
        $cancelled = [];
        $transport->onCancel(static function (RequestId $id) use (&$cancelled): void {
            $cancelled[] = $id->id;
        });

        async(static function () use ($transport): void {
            $response = $transport->handle(self::progressRequest(7));
            $response->getBody()->close();
            delay(0.01);
        })->await();

        self::assertCount(1, $cancelled, 'Abandoning a stream the progress upgrade opened must cancel its request.');
    }

    public function testConstructorRejectsNonPositiveKeepAliveInterval(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^The SSE keep-alive interval must be positive, 0 given\.$/');

        new StreamableHttpServerTransport($factory, $factory, keepAliveInterval: 0.0);
    }

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
        ($server ?? (new ServerBuilder())->setServerInfo('demo', '1.0.0')->build())->listen($transport);
    }

    /**
     * A tool result whose text is a bare UTF-8 continuation byte, which `json_encode` refuses.
     *
     * @param array<string, mixed> $envelope
     */
    private static function unencodableResponse(array $envelope): CallToolResultResponse
    {
        $id = $envelope['id'] ?? null;
        self::assertIsInt($id);

        return new CallToolResultResponse(
            id: new RequestId(id: $id),
            result: new CallToolResult(content: [new TextContent(text: "\xB1\x31")]),
        );
    }

    /**
     * A response factory that fails its first call and delegates every later one.
     */
    private static function factoryFailingOnce(): ResponseFactoryInterface
    {
        return new class implements ResponseFactoryInterface {
            private int $calls = 0;
            private readonly Psr17Factory $delegate;

            public function __construct()
            {
                $this->delegate = new Psr17Factory();
            }

            #[\Override]
            public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
            {
                if (1 === ++$this->calls) {
                    throw new \RuntimeException('response factory is broken');
                }

                return $this->delegate->createResponse($code, $reasonPhrase);
            }
        };
    }

    /**
     * A `subscriptions/listen` POST, which streams in every response mode.
     */
    private static function listenPost(): ServerRequestInterface
    {
        return self::makePost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'subscriptions/listen',
            'params' => ['notifications' => [], '_meta' => RequestMetaObjectFactory::shape()],
        ], self::standardHeaders('subscriptions/listen'));
    }

    private static function unencodableProgress(): ProgressNotification
    {
        return new ProgressNotification(params: new ProgressNotificationParams(
            progressToken: new ProgressToken('tok-1'),
            progress: 0.5,
            message: "\xB1\x31",
        ));
    }

    private static function discoverPost(int|string $id = 1): ServerRequestInterface
    {
        return self::makePost([
            'jsonrpc' => '2.0',
            'id' => $id,
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
     * @return array{ResponseInterface, string}
     */
    private static function handleAndRead(StreamableHttpServerTransport $transport, ServerRequestInterface $request): array
    {
        $response = async(static fn(): ResponseInterface => $transport->handle($request))->await();
        self::assertInstanceOf(ResponseInterface::class, $response);

        $body = async(static fn(): string => (string) $response->getBody())->await();
        self::assertIsString($body);

        return [$response, $body];
    }

    private static function progressServer(float $busyFor = 0.0): Server
    {
        return (new ServerBuilder())
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
