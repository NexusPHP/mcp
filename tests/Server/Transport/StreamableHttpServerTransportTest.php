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
        $transport = $this->makeTransport();
        $factory = new Psr17Factory();

        $response = $transport->handle($factory->createServerRequest('GET', 'https://mcp.test/'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
        self::assertSame('', (string) $response->getBody());
    }

    #[DataProvider('provideUndecodableBodyReturnsParseErrorCases')]
    public function testUndecodableBodyReturnsParseError(string $body): void
    {
        $response = $this->makeTransport()->handle($this->makePost($body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::ParseError->value, $this->readErrorPayload($response)['code'] ?? null);
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

    public function testUndecodableBodyLogsAndFiresErrorListener(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger);
        $errors = [];
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $transport->handle($this->makePost('{not json}'));

        $matches = $logger->recordsMatching(LogLevel::WARNING, '{label} transport rejected a malformed JSON body.');
        self::assertCount(1, $matches);
        self::assertSame('Streamable HTTP server', $matches[0]['context']['label'] ?? null);
        self::assertInstanceOf(\JsonException::class, $matches[0]['context']['exception'] ?? null);
        self::assertCount(1, $errors);
        self::assertInstanceOf(\JsonException::class, $errors[0]);
    }

    public function testNonObjectEnvelopeLogsAndFiresErrorListener(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger);
        $errors = [];
        $transport->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $transport->handle($this->makePost('[1,2,3]'));

        $matches = $logger->recordsMatching(LogLevel::WARNING, '{label} transport rejected a non-object envelope.');
        self::assertCount(1, $matches);
        self::assertSame('Streamable HTTP server', $matches[0]['context']['label'] ?? null);
        self::assertInstanceOf(\InvalidArgumentException::class, $matches[0]['context']['exception'] ?? null);
        self::assertCount(1, $errors);
        self::assertInstanceOf(\InvalidArgumentException::class, $errors[0]);
    }

    #[DataProvider('provideValidJsonThatIsNotAnObjectReturnsInvalidRequestCases')]
    public function testValidJsonThatIsNotAnObjectReturnsInvalidRequest(string $body): void
    {
        $response = $this->makeTransport()->handle($this->makePost($body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $this->readErrorPayload($response)['code'] ?? null);
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
        $response = $this->makeTransport()->handle($this->makePost($body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $this->readErrorPayload($response)['code'] ?? null);
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

    public function testABodyNamingBothAMethodAndAResultWithoutAnIdIsAcceptedAndLeftUnanswered(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $response = $this->handle($transport, $this->makePost(
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'result' => null],
            self::buildStandardHeaders('tools/list'),
        ));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testABodyCarryingNeitherMethodNorResultNorErrorIsAnsweredWithTheEchoedId(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $response = $this->handle($transport, $this->makePost(
            ['jsonrpc' => '2.0', 'id' => 5],
            self::buildStandardHeaders('server/discover'),
        ));

        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $this->readErrorPayload($response)['code'] ?? null);
        self::assertSame(5, $this->decode($response)['id'] ?? null);
    }

    public function testABodyNamingBothAMethodAndAResultIsAnsweredWithTheEchoedId(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $response = $this->handle($transport, $this->makePost([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
            'result' => null,
        ], self::buildStandardHeaders('server/discover')));

        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $this->readErrorPayload($response)['code'] ?? null);
        self::assertSame(9, $this->decode($response)['id'] ?? null);
    }

    #[DataProvider('providePresentButMalformedIdReturnsInvalidRequestCases')]
    public function testPresentButMalformedIdReturnsInvalidRequest(mixed $id): void
    {
        $response = $this->makeTransport()->handle($this->makePost([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $this->readErrorPayload($response)['code'] ?? null);
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
        $transport = $this->makeTransport();
        $token = new VerifiedAccessToken(['https://mcp.test/'], 2_000_000_000, ['files:read']);

        $contexts = [];
        $transport->onMessage(static function (array $envelope, ReceiveContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });

        $transport->handle($this->makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ])->withAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE, $token));

        self::assertCount(1, $contexts);
        self::assertSame($token, $contexts[0]->authInfo);
    }

    public function testTheValidatedTokenReachesHandlersOfABufferedRequest(): void
    {
        $transport = $this->makeTransport(start: false);
        $token = new VerifiedAccessToken(['https://mcp.test/'], 2_000_000_000, ['files:read']);

        $contexts = [];
        $this->listen($transport);
        $transport->onMessage(static function (array $envelope, ReceiveContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });

        $this->handle($transport, $this->makePost([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::buildStandardHeaders('server/discover'))->withAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE, $token));

        self::assertCount(1, $contexts);
        self::assertSame($token, $contexts[0]->authInfo);
    }

    public function testTheValidatedTokenReachesHandlersOfAStreamedRequest(): void
    {
        $transport = $this->makeTransport(responseMode: ResponseMode::Sse, start: false);
        $token = new VerifiedAccessToken(['https://mcp.test/'], 2_000_000_000, ['files:read']);

        $contexts = [];
        $this->listen($transport, $this->buildProgressServer());
        $transport->onMessage(static function (array $envelope, ReceiveContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });

        $this->handleAndRead(
            $transport,
            $this->buildProgressRequest(7)->withAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE, $token),
        );

        self::assertCount(1, $contexts);
        self::assertSame($token, $contexts[0]->authInfo);
    }

    public function testAnUnprotectedEndpointCarriesNoTokenOnTheReceiveContext(): void
    {
        $transport = $this->makeTransport();

        $contexts = [];
        $transport->onMessage(static function (array $envelope, ReceiveContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });

        $transport->handle($this->makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ]));

        self::assertCount(1, $contexts);
        self::assertNull($contexts[0]->authInfo);
    }

    public function testAnAttributeThatIsNotATokenIsIgnored(): void
    {
        $transport = $this->makeTransport();

        $contexts = [];
        $transport->onMessage(static function (array $envelope, ReceiveContext $context) use (&$contexts): void {
            $contexts[] = $context;
        });

        $transport->handle($this->makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ])->withAttribute(VerifiedAccessToken::REQUEST_ATTRIBUTE, 'not-a-token'));

        self::assertCount(1, $contexts);
        self::assertNull($contexts[0]->authInfo);
    }

    public function testAListenRequestStreamsEvenUnderTheJsonResponseMode(): void
    {
        $transport = $this->makeTransport(responseMode: ResponseMode::Json);
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

        $response = $transport->handle($this->makePost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'subscriptions/listen',
            'params' => ['notifications' => [], '_meta' => RequestMetaObjectFactory::shape()],
        ], self::buildStandardHeaders('subscriptions/listen')));

        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame(200, $response->getStatusCode());

        $transport->close();
    }

    public function testAClientCancellationNotificationIsAcceptedButNotDispatched(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport(logger: $logger);

        $received = [];
        $transport->onMessage(static function (array $envelope) use (&$received): void {
            $received[] = $envelope;
        });

        $response = $transport->handle($this->makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 1],
        ]));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame([], $received, 'A foreign id space must never reach the cancellation registry.');
        $records = $logger->recordsMatching(LogLevel::DEBUG, '{label} transport ignored a client cancellation notification: the response stream is the signal on this transport.');
        self::assertCount(1, $records);
        self::assertSame(['label' => 'Streamable HTTP server'], $records[0]['context']);
    }

    public function testValidNotificationIsEmittedAndAcceptedWith202(): void
    {
        $transport = $this->makeTransport();

        $received = [];
        $transport->onMessage(static function (array $envelope) use (&$received): void {
            $received[] = $envelope;
        });

        $response = $transport->handle($this->makePost([
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
        $transport = $this->makeTransport($logger);

        $emitted = false;
        $transport->onMessage(static function () use (&$emitted): void {
            $emitted = true;
        });

        $response = $transport->handle($this->makePost($body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $this->readErrorPayload($response)['code'] ?? null);
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
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $response = $this->handle($transport, $this->makePost([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::buildStandardHeaders('server/discover')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = $this->decode($response);
        self::assertSame('2.0', $body['jsonrpc'] ?? null);
        self::assertSame(7, $body['id'] ?? null);
        self::assertArrayHasKey('result', $body);
    }

    public function testStringClientIdIsRestoredOnTheResponse(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $response = $this->handle($transport, $this->makePost([
            'jsonrpc' => '2.0',
            'id' => 'req-abc',
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::buildStandardHeaders('server/discover')));

        self::assertSame('req-abc', $this->decode($response)['id'] ?? null);
    }

    public function testResponseBodyEncodesAnEmptyObjectSlotAsAnObjectNotAnArray(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $response = $this->handle($transport, $this->discoverPost());

        self::assertStringContainsString('"capabilities":{}', (string) $response->getBody());
    }

    public function testResponseBodyLeavesSlashesAndUnicodeUnescaped(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $response = $this->handle($transport, $this->makePost([
            'jsonrpc' => '2.0',
            'id' => 'scope/日本',
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::buildStandardHeaders('server/discover')));

        $raw = (string) $response->getBody();
        self::assertStringContainsString('scope/日本', $raw);
        self::assertStringNotContainsString('scope\/', $raw);
    }

    public function testConcurrentRequestsSharingAClientIdDoNotCollide(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $post = $this->makePost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::buildStandardHeaders('server/discover'));

        $firstPending = async(static fn(): ResponseInterface => $transport->handle($post));
        $secondPending = async(fn(): ResponseInterface => $transport->handle($this->makePost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::buildStandardHeaders('server/discover'))));

        $first = $firstPending->await();
        $second = $secondPending->await();
        self::assertInstanceOf(ResponseInterface::class, $first);
        self::assertInstanceOf(ResponseInterface::class, $second);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame(1, $this->decode($first)['id'] ?? null);
        self::assertSame(1, $this->decode($second)['id'] ?? null);
        self::assertArrayHasKey('result', $this->decode($first));
        self::assertArrayHasKey('result', $this->decode($second));
    }

    public function testInternalRequestIdsAscendFromOneOnTheStreamingPath(): void
    {
        $transport = $this->makeTransport(responseMode: ResponseMode::Sse);
        $log = $this->captureInternalIds($transport);

        $transport->handle($this->discoverPost());
        $transport->handle($this->discoverPost());
        $transport->handle($this->discoverPost());

        self::assertSame([1, 2, 3], $log->ids);
    }

    public function testInternalRequestIdsAscendFromOneOnTheBufferedPath(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);
        $log = $this->captureInternalIds($transport);

        $this->handle($transport, $this->discoverPost());
        $this->handle($transport, $this->discoverPost());
        $this->handle($transport, $this->discoverPost());

        self::assertSame([1, 2, 3], $log->ids);
    }

    public function testAReleasedInternalIdIsNeverMintedAgain(): void
    {
        $transport = $this->makeTransport(responseMode: ResponseMode::Sse);
        $log = $this->captureInternalIds($transport);

        $body = $transport->handle($this->discoverPost())->getBody();
        $body->close();
        $transport->handle($this->discoverPost());

        self::assertSame([1, 2], $log->ids, 'The retired id must not be handed to the next request.');
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    #[DataProvider('provideHeaderValidationFailureReturnsHeaderMismatchCases')]
    public function testHeaderValidationFailureReturnsHeaderMismatch(array $headers, array $body): void
    {
        $response = $this->makeTransport()->handle($this->makePost($body, $headers));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::HeaderMismatch->value, $this->readErrorPayload($response)['code'] ?? null);

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
            self::buildStandardHeaders('tools/call', 'wrong'),
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_weather', '_meta' => RequestMetaObjectFactory::shape()]],
        ];
    }

    public function testUnknownMethodRidesHttp404(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $response = $this->handle($transport, $this->makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'does/not/exist'],
            ['MCP-Protocol-Version' => ProtocolVersion::LATEST_VERSION, 'Mcp-Method' => 'does/not/exist'],
        ));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $this->readErrorPayload($response)['code'] ?? null);
    }

    public function testEnvelopeLevelInvalidParamsRidesHttp400(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $response = $this->handle($transport, $this->makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => []]],
            self::buildStandardHeaders('server/discover'),
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidParams->value, $this->readErrorPayload($response)['code'] ?? null);
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

        $transport = $this->makeTransport(start: false);
        $this->listen($transport, $server);

        $response = $this->handle($transport, $this->makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => RequestMetaObjectFactory::shape()]],
            self::buildStandardHeaders('server/discover'),
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidParams->value, $this->readErrorPayload($response)['code'] ?? null);
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

        $transport = $this->makeTransport(start: false);
        $this->listen($transport, $server);

        $response = $this->handle($transport, $this->makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => RequestMetaObjectFactory::shape()]],
            self::buildStandardHeaders('server/discover'),
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InternalError->value, $this->readErrorPayload($response)['code'] ?? null);
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

        $transport = $this->makeTransport(start: false);
        $this->listen($transport, $server);

        $post = $this->makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => RequestMetaObjectFactory::shape()]],
            self::buildStandardHeaders('server/discover'),
        );

        $this->handle($transport, $post);

        self::assertInstanceOf(ServerContext::class, $captured);

        self::assertSame($post, $captured->receiveContext->request);
    }

    public function testRequestBeforeStartIsRefusedWith503(): void
    {
        $response = $this->makeTransport(start: false)->handle($this->discoverPost());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InternalError->value, $this->readErrorPayload($response)['code'] ?? null);
        self::assertArrayNotHasKey('id', $this->decode($response));
    }

    public function testRequestAfterCloseIsRefusedWith503(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);
        $transport->close();

        $response = $transport->handle($this->discoverPost());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InternalError->value, $this->readErrorPayload($response)['code'] ?? null);
    }

    public function testNotificationBeforeStartIsRefusedWith503(): void
    {
        $response = $this->makeTransport(start: false)->handle($this->makePost([
            'jsonrpc' => '2.0',
            'method' => 'notifications/tools/list_changed',
        ]));

        self::assertSame(503, $response->getStatusCode());
    }

    public function testNotificationMethodSentAsRequestIsAnsweredRatherThanLeftPending(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $response = $this->handle($transport, $this->makePost([
            'jsonrpc' => '2.0',
            'id' => 'n-1',
            'method' => 'notifications/tools/list_changed',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::buildStandardHeaders('notifications/tools/list_changed')));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $this->readErrorPayload($response)['code'] ?? null);
        self::assertSame('n-1', $this->decode($response)['id'] ?? null, 'The client id must be restored on the response.');
    }

    public function testAShedRequestCarriesServiceUnavailable(): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport, (new ServerBuilder())
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

        $occupied = async(fn(): ResponseInterface => $transport->handle($this->discoverPost()));
        delay(0.01);
        $shed = $transport->handle($this->makePost([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::buildStandardHeaders('server/discover')));
        $occupied->await();

        self::assertSame(503, $shed->getStatusCode());
        self::assertSame(SdkErrorCode::Overloaded->value, $this->readErrorPayload($shed)['code'] ?? null);
    }

    public function testResponseCarryingANonSpecErrorCodeResolvesToBadRequest(): void
    {
        $transport = $this->makeTransport();
        $internalId = null;
        $transport->onMessage(static function (array $envelope) use (&$internalId): void {
            $internalId = $envelope['id'] ?? null;
        });

        $pending = async(fn(): ResponseInterface => $transport->handle($this->discoverPost()));
        delay(0.01);

        self::assertIsInt($internalId);
        $transport->send(new JsonRpcErrorResponse(
            id: new RequestId(id: $internalId),
            error: new UnknownProtocolError(code: -32_001, message: 'boom'),
        ));

        $response = $pending->await();
        self::assertInstanceOf(ResponseInterface::class, $response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32_001, $this->readErrorPayload($response)['code'] ?? null);
    }

    public function testNonPostIsAnsweredEvenWhenTheEndpointIsNotAccepting(): void
    {
        $response = $this->makeTransport(start: false)->handle((new Psr17Factory())->createServerRequest('GET', 'https://mcp.test/'));

        self::assertSame(405, $response->getStatusCode());
    }

    public function testSendBeforeStartThrows(): void
    {
        $this->expectException(TransportNotStartedException::class);

        $this->makeTransport(start: false)->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));
    }

    public function testSendAfterCloseThrows(): void
    {
        $transport = $this->makeTransport();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));
    }

    public function testSendDropsNotification(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger);

        $transport->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));

        $records = $logger->recordsMatching(LogLevel::DEBUG, '{label} transport dropped a notification with no related request to stream it to.');
        self::assertCount(1, $records);
        self::assertSame(['label' => 'Streamable HTTP server'], $records[0]['context']);
        self::assertCount(0, $logger->recordsMatching(LogLevel::WARNING, '{label} transport dropped an unexpected server-initiated request.'));
    }

    public function testSendDropsServerInitiatedRequest(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger);

        $transport->send(new DiscoverRequest(
            id: new RequestId(id: 1),
            params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()),
        ));

        $records = $logger->recordsMatching(LogLevel::WARNING, '{label} transport dropped an unexpected server-initiated request.');
        self::assertCount(1, $records);
        self::assertSame(['label' => 'Streamable HTTP server'], $records[0]['context']);
    }

    public function testSendDropsResponseWithoutAnId(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger);

        $transport->send(new JsonRpcErrorResponse(id: null, error: new InternalError(message: 'boom')));

        $records = $logger->recordsMatching(LogLevel::WARNING, '{label} transport discarded a response that carries no id to correlate.');
        self::assertCount(1, $records);
        self::assertSame(['label' => 'Streamable HTTP server'], $records[0]['context']);
        self::assertCount(0, $logger->recordsMatching(LogLevel::WARNING, '{label} transport dropped an unexpected server-initiated request.'));
    }

    #[DataProvider('provideAThrowingListenerUnwindsItsSinkCases')]
    public function testAThrowingListenerUnwindsItsSink(ResponseMode $responseMode): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger, $responseMode);
        $transport->onMessage(static function (): void {
            throw new \RuntimeException('listener blew up');
        });

        try {
            $this->handle($transport, $this->discoverPost());
            self::fail('The transport must not swallow a listener throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('listener blew up', $e->getMessage());
        }

        $transport->send(new JsonRpcErrorResponse(id: new RequestId(id: 1), error: new InternalError(message: 'boom')));

        $records = $logger->recordsMatching(LogLevel::WARNING, '{label} transport discarded an orphan response with no in-flight request.');
        self::assertCount(1, $records, 'A sink left registered by a throwing listener would route this response instead of orphaning it.');
        self::assertSame(['label' => 'Streamable HTTP server'], $records[0]['context']);
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
        $transport = $this->makeTransport($logger, ResponseMode::Json);
        $transport->onMessage(function (array $envelope) use ($transport): void {
            $transport->send($this->buildUnencodableResponse($envelope));
        });

        $response = $this->handle($transport, $this->discoverPost('req-abc'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('req-abc', $this->decode($response)['id'] ?? null, 'The error echoes the id the client sent, not the transport-internal one.');
        self::assertSame(ProtocolErrorCode::InternalError->value, $this->readErrorPayload($response)['code'] ?? null);
        self::assertSame('The response could not be encoded.', $this->readErrorPayload($response)['message'] ?? null);

        $records = $logger->recordsMatching(LogLevel::ERROR, '{label} transport replaced a response JSON cannot encode with an internal error: {reason}.');
        self::assertCount(1, $records);
        self::assertSame(['label' => 'Streamable HTTP server', 'reason' => 'Malformed UTF-8 characters, possibly incorrectly encoded'], $records[0]['context']);
    }

    public function testAStreamedResponseJsonCannotEncodeIsFramedAsAnInternalError(): void
    {
        $transport = $this->makeTransport(responseMode: ResponseMode::Sse, keepAliveInterval: 0.01);
        $transport->onMessage(function (array $envelope) use ($transport): void {
            $transport->send($this->buildUnencodableResponse($envelope));
        });

        $body = $transport->handle($this->discoverPost('req-abc'))->getBody();
        $frame = $body->read(8_192);

        self::assertSame(
            "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":\"req-abc\",\"error\":{\"code\":-32603,\"message\":\"The response could not be encoded.\"}}\n\n",
            $frame,
        );
        self::assertTrue($body->eof(), 'The substituted error still retires the stream, so the consumer reaches end-of-body.');
    }

    public function testANotificationJsonCannotEncodeLeavesTheRequestAnswerable(): void
    {
        $transport = $this->makeTransport();
        $transport->onMessage(function (array $envelope) use ($transport): void {
            $id = $envelope['id'] ?? null;
            self::assertIsInt($id);
            $related = new SendContext(relatedRequestId: new RequestId(id: $id));

            try {
                $transport->send($this->buildUnencodableProgress(), $related);
                self::fail('The transport must not swallow a notification JSON cannot encode.');
            } catch (\JsonException $e) {
                self::assertSame('Malformed UTF-8 characters, possibly incorrectly encoded', $e->getMessage());
            }

            $transport->send(new GenericResultResponse(id: new RequestId(id: $id), result: new EmptyResult()), $related);
        });

        $response = $this->handle($transport, $this->discoverPost());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertArrayHasKey('result', $this->decode($response));
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

        $response = $this->handle($transport, $this->discoverPost());

        self::assertSame(1, $streamFactory->closes, 'The close must land while the response body is being built.');
        self::assertSame(
            200,
            $response->getStatusCode(),
            'The sink is retired before the body is built, so a close in between cannot settle it first.',
        );
    }

    public function testAnUpgradeTheTransportCannotBuildLeavesTheRequestBuffered(): void
    {
        $transport = new StreamableHttpServerTransport($this->buildFactoryFailingOnce(), new Psr17Factory(), new ArrayLogger());
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

        $response = $this->handle($transport, $this->discoverPost());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'), 'A stream that was never handed back leaves the request buffered.');
        self::assertArrayHasKey('result', $this->decode($response));
    }

    public function testAStreamTheTransportCannotBuildRegistersNoSink(): void
    {
        $logger = new ArrayLogger();
        $transport = new StreamableHttpServerTransport($this->buildFactoryFailingOnce(), new Psr17Factory(), $logger, ResponseMode::Sse);
        $transport->start();
        $transport->onMessage(static function (): void {});

        try {
            $transport->handle($this->discoverPost());
            self::fail('The transport must not swallow a response factory failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('response factory is broken', $e->getMessage());
        }

        $transport->send(new JsonRpcErrorResponse(id: new RequestId(id: 1), error: new InternalError(message: 'boom')));

        self::assertCount(
            1,
            $logger->recordsMatching(LogLevel::WARNING, '{label} transport discarded an orphan response with no in-flight request.'),
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
            $this->handle($transport, $this->discoverPost());
            self::fail('Expected the response factory failure to reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('response factory is broken', $e->getMessage(), 'A response that cannot be built fails the request instead of parking it forever.');
        }
    }

    public function testASinkThatCannotBeAnsweredCostsOnlyItself(): void
    {
        $transport = new StreamableHttpServerTransport($this->buildFactoryFailingOnce(), new Psr17Factory(), new ArrayLogger(), ResponseMode::Json);
        $transport->start();
        $transport->onMessage(static function (): void {});

        $closed = false;
        $transport->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        $first = async(fn(): ResponseInterface => $transport->handle($this->discoverPost()));
        $second = async(fn(): ResponseInterface => $transport->handle($this->discoverPost('req-2')));
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
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                if (LogLevel::ERROR === $level) {
                    throw new \RuntimeException('logger is broken');
                }
            }
        };

        $transport = new StreamableHttpServerTransport($factory, $factory, $logger, ResponseMode::Json);
        $transport->start();
        $transport->onMessage(function (array $envelope) use ($transport): void {
            async(function () use ($transport, $envelope): void {
                try {
                    $transport->send($this->buildUnencodableResponse($envelope));
                } catch (\RuntimeException $e) {
                    self::assertSame('logger is broken', $e->getMessage());
                }
            })->ignore();
        });

        $response = $this->handle($transport, $this->discoverPost());

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

        $response = $this->handle($transport, $this->discoverPost());

        self::assertSame(1, $responseFactory->closes, 'The close must land while the SSE response is being built.');
        self::assertSame(503, $response->getStatusCode(), 'A close that settled the request leaves the upgrade nothing to do.');
    }

    public function testCloseRetiresEveryRequestStillInFlight(): void
    {
        $transport = $this->makeTransport(responseMode: ResponseMode::Json, keepAliveInterval: 0.01);
        $transport->onMessage(static function (): void {});

        $cancelled = [];
        $transport->onCancel(static function (RequestId $id) use (&$cancelled): void {
            $cancelled[] = $id->id;
        });

        $streamed = $transport->handle($this->listenPost())->getBody();
        $buffered = async(fn(): ResponseInterface => $transport->handle($this->discoverPost()));
        delay(0.0);

        $transport->close();

        self::assertSame('', $streamed->read(8_192), 'An ended stream reads empty instead of yielding another keep-alive.');
        self::assertTrue($streamed->eof());

        $streamed->close();
        self::assertSame([], $cancelled, 'A stream retired at close is no longer registered, so closing its body reports no cancellation.');

        $response = $buffered->await();
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(1, $this->decode($response)['id'] ?? null, 'The error echoes the id the client sent, not the transport-internal one.');
        self::assertSame(ProtocolErrorCode::InternalError->value, $this->readErrorPayload($response)['code'] ?? null);
        self::assertSame('The MCP endpoint is shutting down.', $this->readErrorPayload($response)['message'] ?? null);
    }

    /**
     * @param int|non-empty-string $id
     */
    #[DataProvider('provideSendDropsOrphanResponseCases')]
    public function testSendDropsOrphanResponse(int|string $id): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger);

        $transport->send(new JsonRpcErrorResponse(id: new RequestId(id: $id), error: new InternalError(message: 'boom')));

        self::assertCount(1, $logger->recordsMatching(LogLevel::WARNING, '{label} transport discarded an orphan response with no in-flight request.'));
        self::assertCount(0, $logger->recordsMatching(LogLevel::WARNING, '{label} transport dropped an unexpected server-initiated request.'));
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
        $transport = $this->makeTransport(start: false);
        $transport->start();

        $this->expectException(TransportAlreadyStartedException::class);

        $transport->start();
    }

    public function testStartAfterCloseThrows(): void
    {
        $transport = $this->makeTransport(start: false);
        $transport->start();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->start();
    }

    public function testListenerChurnIsLoggedAtDebug(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger, start: false);

        $transport->onMessage(static function (): void {});

        $records = $logger->recordsMatching(
            LogLevel::DEBUG,
            '{label} transport {verb} {article} {kind} listener. {count} active.',
        );
        self::assertCount(1, $records);
        self::assertSame(
            ['label' => 'Streamable HTTP server', 'verb' => 'registered', 'article' => 'a', 'kind' => 'message', 'count' => 1],
            $records[0]['context'],
        );
    }

    public function testStartLogsTheStartAtInfo(): void
    {
        $logger = new ArrayLogger();
        $this->makeTransport($logger);

        $matches = $logger->recordsMatching(LogLevel::INFO, '{label} transport started.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'Streamable HTTP server'], $matches[0]['context']);
    }

    public function testCloseLogsTheClosureOnce(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger);

        $transport->close();
        $transport->close();

        $matches = $logger->recordsMatching(LogLevel::INFO, '{label} transport closed.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'Streamable HTTP server'], $matches[0]['context']);
    }

    public function testAConcurrentCloseStillReturnsWhenACloseListenerThrows(): void
    {
        $transport = $this->makeTransport();
        $transport->onDrain(static function (): void {
            delay(0.02);
        });
        $transport->onClose(static function (): void {
            throw new \RuntimeException('close listener blew up');
        });

        $events = [];
        $second = async(static function () use ($transport, &$events): void {
            delay(0.01);
            $transport->close();
            $events[] = 'second:returned';
        });

        try {
            $transport->close();
        } catch (\RuntimeException) {
            $events[] = 'first:threw';
        }

        $second->await();

        self::assertSame(
            ['first:threw', 'second:returned'],
            $events,
            'The close must settle for concurrent closers even when a close listener throws.',
        );
    }

    public function testAConcurrentCloseBlocksUntilTheFirstHasSettled(): void
    {
        $transport = $this->makeTransport();
        $events = [];
        $transport->onDrain(static function () use (&$events): void {
            $events[] = 'drain:start';
            delay(0.02);
            $events[] = 'drain:end';
        });

        $first = async(static function () use ($transport, &$events): void {
            $transport->close();
            $events[] = 'first:returned';
        });
        $second = async(static function () use ($transport, &$events): void {
            delay(0.01);
            $transport->close();
            $events[] = 'second:returned';

            try {
                $transport->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));
                $events[] = 'second:sent';
            } catch (TransportAlreadyClosedException) {
                $events[] = 'second:send-refused';
            }
        });
        $first->await();
        $second->await();

        self::assertSame(
            ['drain:start', 'drain:end', 'first:returned', 'second:returned', 'second:send-refused'],
            $events,
            'A concurrent close must block until the first has settled, and a send after it returns must be refused.',
        );
    }

    public function testCloseDrainsBeforeSignallingCloseAndIsIdempotent(): void
    {
        $transport = $this->makeTransport();

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
        $transport = $this->makeTransport();

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
        $transport = $this->makeTransport();

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
        $response = $this->makeTransport()->handle($this->makePost(
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

        yield 'empty header' => [''];

        yield 'unknown subtype sharing the prefix' => ['application/jsonx, text/event-stream'];

        yield 'wildcard of the wrong type' => ['image/*, text/event-stream'];

        yield 'event-stream disabled by q=0' => ['application/json, text/event-stream;q=0'];

        yield 'json disabled by q=0' => ['application/json;q=0.000, text/event-stream'];

        yield 'event-stream disabled by an uppercase Q=0' => ['application/json, text/event-stream;Q=0'];
    }

    #[DataProvider('provideAcceptHeaderMatchesMediaRangesCases')]
    public function testAcceptHeaderMatchesMediaRanges(string $accept): void
    {
        $transport = $this->makeTransport(start: false);
        $this->listen($transport);

        $response = $this->handle($transport, $this->makePost(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => RequestMetaObjectFactory::shape()]],
            ['Accept' => $accept] + self::buildStandardHeaders('server/discover'),
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('result', $this->decode($response));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptHeaderMatchesMediaRangesCases(): iterable
    {
        yield 'mixed case' => ['Application/JSON, Text/Event-Stream'];

        yield 'any media type' => ['*/*'];

        yield 'type wildcards' => ['application/*, text/*'];

        yield 'parameters beside the type' => ['application/json;charset=utf-8, text/event-stream'];

        yield 'positive qualities and spacing' => [' application/json ; Q=0.5 , text/event-stream ;q=0.8 '];

        yield 'a q=0 range beside an acceptable one' => ['text/plain;q=0, application/json, text/event-stream'];
    }

    public function testSseModeStreamsProgressThenTheFinalResult(): void
    {
        $transport = $this->makeTransport(responseMode: ResponseMode::Sse, start: false);
        $this->listen($transport, $this->buildProgressServer());

        [$response, $body] = $this->handleAndRead($transport, $this->buildProgressRequest(7));

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
        $transport = $this->makeTransport(responseMode: ResponseMode::Sse, keepAliveInterval: 0.02, start: false);
        $this->listen($transport, $this->buildProgressServer());

        $eof = async(function () use ($transport): string {
            $body = $transport->handle($this->buildProgressRequest(7))->getBody();
            $body->read(65_536);

            return $body->read(65_536);
        })->await();

        self::assertSame('', $eof);
    }

    public function testAutoModeUpgradesToSseWhenProgressArrives(): void
    {
        $transport = $this->makeTransport(responseMode: ResponseMode::Auto, start: false);
        $this->listen($transport, $this->buildProgressServer());

        [$response, $body] = $this->handleAndRead($transport, $this->buildProgressRequest(7));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('notifications/progress', $body);
        self::assertStringContainsString('"result"', $body);
    }

    public function testJsonModeBuffersAndDropsProgress(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger, ResponseMode::Json, start: false);
        $this->listen($transport, $this->buildProgressServer());

        $response = $this->handle($transport, $this->buildProgressRequest(7));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertArrayHasKey('result', $this->decode($response));
        $records = $logger->recordsMatching(LogLevel::DEBUG, '{label} transport dropped a notification: the JSON response mode cannot stream it.');
        self::assertCount(1, $records);
        self::assertSame(['label' => 'Streamable HTTP server'], $records[0]['context']);
    }

    public function testStreamEmitsKeepAliveFramesWhileTheHandlerIsBusy(): void
    {
        $transport = $this->makeTransport(responseMode: ResponseMode::Sse, keepAliveInterval: 0.01, start: false);
        $this->listen($transport, $this->buildProgressServer(busyFor: 0.03));

        [, $body] = $this->handleAndRead($transport, $this->buildProgressRequest(7));

        self::assertStringContainsString(': keep-alive', $body);
        self::assertStringContainsString('"result"', $body);
    }

    public function testClosingTheBodyCancelsTheInFlightRequest(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger, ResponseMode::Sse, start: false);
        $this->listen($transport, $this->buildProgressServer());

        async(function () use ($transport): void {
            $response = $transport->handle($this->buildProgressRequest(7));
            $response->getBody()->close();
            delay(0.01);
        })->await();

        self::assertSame(
            [],
            $logger->recordsMatching(LogLevel::WARNING, '{label} transport discarded an orphan response with no in-flight request.'),
            'A disconnect cancels the request, so no response is produced for the transport to orphan.',
        );
        $records = $logger->recordsMatching(LogLevel::DEBUG, '{label} transport dropped a notification for a request that is no longer in flight.');
        self::assertCount(1, $records);
        self::assertSame(['label' => 'Streamable HTTP server'], $records[0]['context']);
    }

    public function testAGracefullyEndedStreamDoesNotCancelAnything(): void
    {
        $transport = $this->makeTransport(new ArrayLogger(), ResponseMode::Sse, start: false);
        $this->listen($transport, $this->buildProgressServer());
        $cancelled = [];
        $transport->onCancel(static function (RequestId $id) use (&$cancelled): void {
            $cancelled[] = $id->id;
        });

        async(function () use ($transport): void {
            $response = $transport->handle($this->buildProgressRequest(7));
            delay(0.01);
            $response->getBody()->close();
        })->await();

        self::assertSame([], $cancelled);
    }

    public function testADisconnectReportsTheAbandonedRequestToItsListeners(): void
    {
        $transport = $this->makeTransport(new ArrayLogger(), ResponseMode::Sse, start: false);
        $this->listen($transport, $this->buildProgressServer(busyFor: 0.05));
        $cancelled = [];
        $subscription = $transport->onCancel(static function (RequestId $id) use (&$cancelled): void {
            $cancelled[] = $id->id;
        });

        async(function () use ($transport): void {
            $transport->handle($this->buildProgressRequest(7))->getBody()->close();
            delay(0.01);
        })->await();

        self::assertCount(1, $cancelled);
        $subscription->dispose();

        async(function () use ($transport): void {
            $transport->handle($this->buildProgressRequest(8))->getBody()->close();
            delay(0.01);
        })->await();

        self::assertCount(1, $cancelled, 'A disposed listener stops hearing about disconnects.');
    }

    public function testADisconnectOnAnUpgradedStreamReportsTheAbandonedRequest(): void
    {
        $transport = $this->makeTransport(new ArrayLogger(), ResponseMode::Auto, start: false);
        $this->listen($transport, $this->buildProgressServer(busyFor: 0.05));
        $cancelled = [];
        $transport->onCancel(static function (RequestId $id) use (&$cancelled): void {
            $cancelled[] = $id->id;
        });

        async(function () use ($transport): void {
            $response = $transport->handle($this->buildProgressRequest(7));
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

    #[DataProvider('provideConstructorRejectsANonPositiveBufferCapCases')]
    public function testConstructorRejectsANonPositiveBufferCap(int $cap): void
    {
        $factory = new Psr17Factory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs(\sprintf('The SSE buffer cap must be positive, %d given.', $cap));

        // @phpstan-ignore argument.type
        new StreamableHttpServerTransport($factory, $factory, maxBufferedBytes: $cap);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideConstructorRejectsANonPositiveBufferCapCases(): iterable
    {
        yield 'zero' => [0];

        yield 'negative' => [-1];
    }

    public function testAStreamWhoseReaderFallsBehindIsAbandoned(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger, ResponseMode::Sse, start: false, maxBufferedBytes: 1);
        $this->listen($transport, (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->replaceRequestHandler('server/discover', new ClosureRequestHandler(
                static function (JsonRpcRequest $request, AbstractContext $context): Result {
                    $context->reportProgress(0.25, 1.0, 'first');
                    $context->reportProgress(0.5, 1.0, 'second');

                    return new EmptyResult();
                },
            ))
            ->build());
        $cancelled = [];
        $transport->onCancel(static function (RequestId $id) use (&$cancelled): void {
            $cancelled[] = $id->id;
        });

        async(function () use ($transport): void {
            $transport->handle($this->buildProgressRequest(7));
            delay(0.01);
        })->await();

        self::assertCount(1, $cancelled, 'An overflowed stream abandons its request as a disconnect would.');
        $records = $logger->recordsMatching(LogLevel::WARNING, '{label} transport abandoned a stream whose reader fell at least {limit} bytes behind.');
        self::assertCount(1, $records);
        self::assertSame(['label' => 'Streamable HTTP server', 'limit' => 1], $records[0]['context']);
    }

    public function testAStreamWhoseReaderKeepsUpIsNotAbandoned(): void
    {
        $logger = new ArrayLogger();
        $transport = $this->makeTransport($logger, ResponseMode::Sse, start: false, maxBufferedBytes: 1);
        $this->listen($transport, $this->buildProgressServer(busyFor: 0.03));
        $cancelled = [];
        $transport->onCancel(static function (RequestId $id) use (&$cancelled): void {
            $cancelled[] = $id->id;
        });

        [, $body] = $this->handleAndRead($transport, $this->buildProgressRequest(7));

        self::assertSame([], $cancelled);
        self::assertSame([], $logger->messagesAtLevel(LogLevel::WARNING));
        self::assertStringContainsString('"result"', $body);
    }

    private function captureInternalIds(StreamableHttpServerTransport $transport): RequestIdLog
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
     * @param int<1, max> $maxBufferedBytes
     */
    private function makeTransport(
        ?ArrayLogger $logger = null,
        ResponseMode $responseMode = ResponseMode::Auto,
        float $keepAliveInterval = 15.0,
        bool $start = true,
        int $maxBufferedBytes = 1_048_576,
    ): StreamableHttpServerTransport {
        $factory = new Psr17Factory();
        $transport = new StreamableHttpServerTransport($factory, $factory, $logger ?? new ArrayLogger(), $responseMode, $keepAliveInterval, $maxBufferedBytes);

        if ($start) {
            $transport->start();
        }

        return $transport;
    }

    private function listen(StreamableHttpServerTransport $transport, ?Server $server = null): void
    {
        ($server ?? (new ServerBuilder())->setServerInfo('demo', '1.0.0')->build())->listen($transport);
    }

    /**
     * A tool result whose text is a bare UTF-8 continuation byte, which `json_encode` refuses.
     *
     * @param array<string, mixed> $envelope
     */
    private function buildUnencodableResponse(array $envelope): CallToolResultResponse
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
    private function buildFactoryFailingOnce(): ResponseFactoryInterface
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
    private function listenPost(): ServerRequestInterface
    {
        return $this->makePost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'subscriptions/listen',
            'params' => ['notifications' => [], '_meta' => RequestMetaObjectFactory::shape()],
        ], self::buildStandardHeaders('subscriptions/listen'));
    }

    private function buildUnencodableProgress(): ProgressNotification
    {
        return new ProgressNotification(params: new ProgressNotificationParams(
            progressToken: new ProgressToken('tok-1'),
            progress: 0.5,
            message: "\xB1\x31",
        ));
    }

    private function discoverPost(int|string $id = 1): ServerRequestInterface
    {
        return $this->makePost([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ], self::buildStandardHeaders('server/discover'));
    }

    /**
     * Builds a POST, defaulting the required dual `Accept` header when the caller does not set one.
     *
     * @param array<string, mixed>|string $body
     * @param array<string, string>       $headers
     */
    private function makePost(array|string $body, array $headers = []): ServerRequestInterface
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
    private function handleAndRead(StreamableHttpServerTransport $transport, ServerRequestInterface $request): array
    {
        $response = async(static fn(): ResponseInterface => $transport->handle($request))->await();
        self::assertInstanceOf(ResponseInterface::class, $response);

        $body = async(static fn(): string => (string) $response->getBody())->await();
        self::assertIsString($body);

        return [$response, $body];
    }

    private function buildProgressServer(float $busyFor = 0.0): Server
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

    private function buildProgressRequest(int|string $id): ServerRequestInterface
    {
        return $this->makePost([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape(progressToken: new ProgressToken('tok-1'))],
        ], self::buildStandardHeaders('server/discover'));
    }

    /**
     * @return array<string, string>
     */
    private static function buildStandardHeaders(string $method, ?string $name = null): array
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

    private function handle(StreamableHttpServerTransport $transport, ServerRequestInterface $request): ResponseInterface
    {
        $response = async(static fn(): ResponseInterface => $transport->handle($request))->await();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return $response;
    }

    /**
     * @return array<mixed, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<mixed, mixed>
     */
    private function readErrorPayload(ResponseInterface $response): array
    {
        $body = $this->decode($response);
        self::assertArrayHasKey('error', $body);
        $error = $body['error'];
        self::assertIsArray($error);

        return $error;
    }
}
