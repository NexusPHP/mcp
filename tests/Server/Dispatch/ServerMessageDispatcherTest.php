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

namespace Nexus\Mcp\Tests\Server\Dispatch;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Nexus\Mcp\Core\Dispatch\PendingInboundRequests;
use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Exception\MethodMisroutedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\HandlerRegistry;
use Nexus\Mcp\Core\Handler\Notification\CancelledNotificationHandler;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMessageParser;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Enum\SdkErrorCode;
use Nexus\Mcp\Core\Schema\Error\UnsupportedProtocolVersionError;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\MetaObject\GenericResultMetaObject;
use Nexus\Mcp\Core\Schema\MetaObject\ResultMetaObject;
use Nexus\Mcp\Core\Schema\Notification\ProgressNotification;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Server\Dispatch\ServerMessageDispatcher;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\Handler\Request\DiscoverRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\TestLooseClientRequest;
use Nexus\Mcp\Tests\Fixtures\Core\TestRequest;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(ServerMessageDispatcher::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ServerMessageDispatcherTest extends AbstractMcpTestCase
{
    /**
     * Seconds a cancellation test's handler waits, since `RecordingTransport` holds no I/O and needs self-terminating referenced loop work.
     */
    private const float CANCELLATION_ANCHOR = 1.0;

    private const string ORPHAN_LOG_MESSAGE = 'Discarded {count} response envelope(s) so far (server has no outbound-request correlation).';

    public function testInboundResultResponseEnvelopeIsLoggedAndDropped(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'result' => []];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(LogLevel::WARNING, self::ORPHAN_LOG_MESSAGE);
        self::assertCount(1, $matches);
        self::assertSame(['count' => 1], $matches[0]['context']);
    }

    public function testInboundErrorResponseEnvelopeIsLoggedAndDropped(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32_603, 'message' => 'oops']];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(LogLevel::WARNING, self::ORPHAN_LOG_MESSAGE);
        self::assertCount(1, $matches);
        self::assertSame(['count' => 1], $matches[0]['context']);
    }

    public function testAnOrphanResponseStormIsLoggedOnceNotOncePerEnvelope(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        for ($i = 0; $i < 25; ++$i) {
            $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => $i, 'result' => []], $transport, new ReceiveContext());
        }

        $dispatcher->flushPending();

        $matches = $logger->recordsMatching(LogLevel::WARNING, self::ORPHAN_LOG_MESSAGE);
        self::assertCount(1, $matches);
        self::assertSame(['count' => 1], $matches[0]['context']);
    }

    public function testMalformedResponseEnvelopeIsDroppedNotAnsweredWithError(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'result' => 'opaque-string'];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame([], $transport->sent, 'A response envelope must not provoke another response, even when malformed.');
        self::assertCount(1, $logger->recordsMatching(LogLevel::WARNING, self::ORPHAN_LOG_MESSAGE));
        self::assertSame([], $logger->messagesAtLevel(LogLevel::INFO), 'Response envelopes must not fall through to the notification-drop log.');
    }

    public function testAnIdCarryingEnvelopeNamingBothAMethodAndAResultIsAnsweredNotDropped(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list', 'result' => null];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(9, $message->id?->id);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $message->error->code);
        self::assertSame([], $logger->recordsMatching(LogLevel::WARNING, self::ORPHAN_LOG_MESSAGE));
    }

    public function testAnEnvelopeCarryingNeitherMethodNorResultNorErrorIsAnswered(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 5], $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(5, $message->id?->id);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $message->error->code);
        self::assertSame([], $logger->recordsMatching(LogLevel::WARNING, self::ORPHAN_LOG_MESSAGE));
    }

    public function testAnEnvelopeNamingBothAMethodAndAResultWithoutAnIdStaysUnanswered(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'method' => 'tools/list', 'result' => null], $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame([], $transport->sent);
    }

    public function testParseFailureSendsErrorResponseWithRecoveredId(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher();

        $dispatcher->dispatch(['jsonrpc' => '1.0', 'id' => 7, 'method' => 'tools/list'], $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(7, $message->id?->id);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $message->error->code);
    }

    public function testParseFailureForNotificationEnvelopeIsDroppedAndLoggedNotAnsweredWithError(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '1.0'];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame([], $transport->sent, 'JSON-RPC 2.0 §4.1 forbids responses to notifications.');
        $matches = $logger->recordsMatching(
            LogLevel::INFO,
            'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).',
        );
        self::assertCount(1, $matches);
        self::assertInstanceOf(AbstractJsonRpcProtocolException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testRequestMethodSentAsNotificationIsDroppedAndLoggedNotAnsweredWithError(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'method' => 'tools/list'];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame([], $transport->sent, 'An envelope without an id is a notification whatever method it names, and JSON-RPC 2.0 §4.1 forbids answering one.');

        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Rejecting envelope whose method was sent under the wrong JSON-RPC shape.',
        );
        self::assertCount(1, $matches);
        self::assertInstanceOf(MethodMisroutedException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testNotificationMethodSentAsRequestIsAnsweredWithInvalidRequestEchoingTheId(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'notifications/cancelled'];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);

        self::assertInstanceOf(RequestId::class, $message->id);

        self::assertSame(7, $message->id->id);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $message->error->code);
        self::assertStringContainsString('"notifications/cancelled"', $message->error->message);

        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Rejecting envelope whose method was sent under the wrong JSON-RPC shape.',
        );
        self::assertCount(1, $matches);
        self::assertInstanceOf(MethodMisroutedException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testARequestParsedWithoutParamsIsRejectedAsInvalid(): void
    {
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                TestLooseClientRequest::getMethod() => new ClosureRequestHandler(
                    static fn(): EmptyResult => throw new \RuntimeException('The guard must reject before the handler runs.'),
                ),
            ],
            parser: new JsonRpcMessageParser([TestLooseClientRequest::getMethod() => TestLooseClientRequest::class]),
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => TestLooseClientRequest::getMethod(),
        ], $transport, new ReceiveContext());
        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);

        self::assertInstanceOf(RequestId::class, $message->id);

        self::assertSame(5, $message->id->id);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $message->error->code);
        self::assertSame('"params" must be an object carrying the lifecycle "_meta" fields.', $message->error->message);
    }

    public function testUnknownNotificationMethodIsDroppedAndLoggedNotAnsweredWithError(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'method' => 'notifications/vendor/unknown'];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(
            LogLevel::INFO,
            'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).',
        );
        self::assertCount(1, $matches);
    }

    public function testNotificationWithMalformedParamsIsDroppedAndLoggedNotAnsweredWithError(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => null],
        ];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(
            LogLevel::INFO,
            'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).',
        );
        self::assertCount(1, $matches);
    }

    public function testRequestForUnknownMethodReturnsMethodNotFoundError(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher();

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'id' => 'req-1', 'method' => 'vendor/unknown'],
            $transport,
            new ReceiveContext(),
        );

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame('req-1', $message->id?->id);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $message->error->code);
    }

    public function testRequestForSpecMethodWithNoRegisteredHandlerReturnsMethodNotFoundError(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher();

        $dispatcher->dispatch(self::toolsListEnvelope('req-2'), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame('req-2', $message->id?->id);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $message->error->code);
        $context = $transport->sent[0]['context'];
        self::assertFalse(null !== $context && $context->fromHandler);
    }

    public function testServerRejectsNonClientRequestMethodWithMethodNotFound(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            parser: new JsonRpcMessageParser(requests: ['tests/test-request' => TestRequest::class]),
        );

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 'r-3', 'method' => 'tests/test-request'], $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent, 'A non-ClientRequest method must draw exactly one response, not a silent drop.');
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame('r-3', $message->id?->id);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $message->error->code);
    }

    public function testNonClientRequestMethodIsRejectedByDirectionEvenWhenAHandlerIsRegistered(): void
    {
        $invoked = false;
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tests/test-request' => new ClosureRequestHandler(
                    static function () use (&$invoked): Result {
                        $invoked = true;

                        return new EmptyResult();
                    },
                ),
            ],
            parser: new JsonRpcMessageParser(requests: ['tests/test-request' => TestRequest::class]),
        );

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 'r-1', 'method' => 'tests/test-request'], $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertFalse($invoked, 'A non-ClientRequest method must be rejected by direction before any registered handler runs.');
        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame('r-1', $message->id?->id);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $message->error->code);
    }

    public function testRequestWithUnsupportedProtocolVersionReturnsTypedErrorAndSkipsHandler(): void
    {
        $invoked = false;
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static function () use (&$invoked): Result {
                        $invoked = true;

                        return new EmptyResult();
                    },
                ),
            ],
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1, protocolVersion: '2025-11-25'), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertFalse($invoked, 'The version gate must reject before the handler runs.');
        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(1, $message->id?->id);

        $error = $message->error;

        self::assertInstanceOf(UnsupportedProtocolVersionError::class, $error);

        self::assertSame(ProtocolErrorCode::UnsupportedProtocolVersion->value, $error->code);
        self::assertSame('2025-11-25', $error->requested);
        self::assertSame(ProtocolVersion::SUPPORTED_VERSIONS, $error->supported);
    }

    public function testAVersionThatIsNotADateStillReachesTheVersionGate(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher();

        $dispatcher->dispatch(self::toolsListEnvelope(1, protocolVersion: 'v999.0.0'), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);

        $error = $message->error;

        self::assertInstanceOf(UnsupportedProtocolVersionError::class, $error);

        self::assertSame('v999.0.0', $error->requested);
    }

    /**
     * @param non-empty-string $requested
     */
    #[DataProvider('provideAHostileProtocolVersionIsBoundedBeforeItReachesErrorDataCases')]
    public function testAHostileProtocolVersionIsBoundedBeforeItReachesErrorData(string $requested, string $expected): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher();

        $dispatcher->dispatch(self::toolsListEnvelope(1, protocolVersion: $requested), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);

        $error = $message->error;

        self::assertInstanceOf(UnsupportedProtocolVersionError::class, $error);

        self::assertSame($expected, $error->requested);
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function provideAHostileProtocolVersionIsBoundedBeforeItReachesErrorDataCases(): iterable
    {
        yield 'oversized' => [str_repeat('A', 200), str_repeat('A', 80 - 3).'...'];

        yield 'control bytes' => ["2026-07-28\x1b]0;pwned\x07", '2026-07-28\x1b]0;pwned\x07'];
    }

    public function testServerDiscoverIsGatedByTheProtocolVersionToo(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['server/discover' => new DiscoverRequestHandler(new ServerCapabilities())],
        );

        $dispatcher->dispatch(self::discoverEnvelope(2, protocolVersion: '2025-11-25'), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::UnsupportedProtocolVersion->value, $message->error->code);
    }

    public function testUnsupportedProtocolVersionTakesPrecedenceOverMissingHandler(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher();

        $dispatcher->dispatch(self::toolsListEnvelope(3, protocolVersion: '2025-11-25'), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(
            ProtocolErrorCode::UnsupportedProtocolVersion->value,
            $message->error->code,
            'The version gate must fire before handler resolution.',
        );
    }

    public function testSuccessfulRequestSendsResultResponse(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['server/discover' => new DiscoverRequestHandler(new ServerCapabilities())],
        );

        $dispatcher->dispatch(self::discoverEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $message);
        self::assertSame(1, $message->id->id);
        self::assertInstanceOf(DiscoverResult::class, $message->result);
    }

    public function testStampsTheConfiguredServerIdentityOnTheResult(): void
    {
        $serverInfo = new Implementation(name: 'test-server', version: '1.0.0');
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => self::okHandler()],
            serverInfo: $serverInfo,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame($serverInfo, self::sentResult($transport)->meta->serverInfo);
    }

    public function testACancelledRequestWhoseHandlerPropagatesTheCancellationIsNotAnswered(): void
    {
        [$dispatcher, $transport, $logger] = self::buildCancellableDispatcher(
            static function ($request, AbstractContext $context): Result {
                delay(self::CANCELLATION_ANCHOR, cancellation: $context->cancellation);

                return new EmptyResult();
            },
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::cancelEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->flushPending();

        self::assertSame([], $transport->sent, 'The spec forbids answering a request whose cancellation was requested.');

        $records = $logger->recordsMatching(
            LogLevel::DEBUG,
            'Dropping the response to a request whose handler the cancellation interrupted.',
        );
        self::assertCount(1, $records);
        self::assertSame(['method' => 'tools/list'], $records[0]['context']);
        self::assertSame(
            [],
            $logger->messagesAtLevel(LogLevel::ERROR),
            'A cancellation is not a handler fault, so it must not be reported as one.',
        );
    }

    public function testACancelledRequestWhoseHandlerReturnsTidilyIsStillNotAnswered(): void
    {
        [$dispatcher, $transport, $logger] = self::buildCancellableDispatcher(
            static function ($request, AbstractContext $context): Result {
                try {
                    delay(self::CANCELLATION_ANCHOR, cancellation: $context->cancellation);
                } catch (CancelledException) {
                }

                return new EmptyResult();
            },
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::cancelEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->flushPending();

        self::assertSame([], $transport->sent);

        $records = $logger->recordsMatching(LogLevel::DEBUG, 'Dropping the response to a cancelled request.');
        self::assertCount(1, $records);
        self::assertSame(['method' => 'tools/list'], $records[0]['context']);
    }

    public function testACancelledRequestIsNotAnsweredWithAProtocolErrorItsHandlerRaised(): void
    {
        [$dispatcher, $transport] = self::buildCancellableDispatcher(
            static function ($request, AbstractContext $context): Result {
                try {
                    delay(self::CANCELLATION_ANCHOR, cancellation: $context->cancellation);
                } catch (CancelledException) {
                }

                throw new InvalidParamsException($context->requestId, 'bad arguments');
            },
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::cancelEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->flushPending();

        self::assertSame([], $transport->sent);
    }

    public function testACancelledRequestIsNotAnsweredWithTheInternalErrorItsHandlerRaised(): void
    {
        [$dispatcher, $transport] = self::buildCancellableDispatcher(
            static function ($request, AbstractContext $context): Result {
                try {
                    delay(self::CANCELLATION_ANCHOR, cancellation: $context->cancellation);
                } catch (CancelledException) {
                }

                throw new \RuntimeException('boom');
            },
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::cancelEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->flushPending();

        self::assertSame([], $transport->sent);
    }

    public function testCancelRequestReachesTheInFlightSetDirectly(): void
    {
        $inboundRequests = new PendingInboundRequests();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => new ClosureRequestHandler(
                static function ($request, AbstractContext $context): Result {
                    delay(self::CANCELLATION_ANCHOR, cancellation: $context->cancellation);

                    return new EmptyResult();
                },
            )],
            inboundRequests: $inboundRequests,
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->cancelRequest(new RequestId(id: 1));
        $dispatcher->flushPending();

        self::assertSame([], $transport->sent);
    }

    public function testAnUncancelledRequestIsStillAnswered(): void
    {
        [$dispatcher, $transport] = self::buildCancellableDispatcher(
            static fn($request, AbstractContext $context): Result => new EmptyResult(),
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
    }

    public function testCancellingOneRequestLeavesAnotherAnswerable(): void
    {
        [$dispatcher, $transport] = self::buildCancellableDispatcher(
            static function ($request, AbstractContext $context): Result {
                if (1 === $context->requestId->id) {
                    delay(self::CANCELLATION_ANCHOR, cancellation: $context->cancellation);
                }

                return new EmptyResult();
            },
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::toolsListEnvelope(2), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::cancelEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent, 'Only the cancelled request loses its response.');
        $message = $transport->sent[0]['message'];

        self::assertInstanceOf(JsonRpcResultResponse::class, $message);

        self::assertSame(2, $message->id->id);
    }

    public function testStampsTheServerIdentityOnAResultThatCarriesOtherMetaEntries(): void
    {
        $serverInfo = new Implementation(name: 'test-server', version: '1.0.0');
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(new GenericResultMetaObject(extras: ['vendor' => 'x'])),
            )],
            serverInfo: $serverInfo,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame(
            [ResultMetaObject::SERVER_INFO_KEY => ['name' => 'test-server', 'version' => '1.0.0'], 'vendor' => 'x'],
            self::sentResult($transport)->meta->toArray(),
        );
    }

    public function testLeavesAnIdentityTheHandlerCarriedAmongTheMetaExtras(): void
    {
        $forwarded = [ResultMetaObject::SERVER_INFO_KEY => ['name' => 'upstream-server', 'version' => '9.9.9']];
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(new GenericResultMetaObject(extras: $forwarded)),
            )],
            serverInfo: new Implementation(name: 'test-server', version: '1.0.0'),
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame($forwarded, self::sentResult($transport)->meta->toArray());
    }

    public function testLeavesAnIdentityTheHandlerDeclaredItselfUntouched(): void
    {
        $upstream = new Implementation(name: 'upstream-server', version: '9.9.9');
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => new ClosureRequestHandler(
                static fn(): EmptyResult => new EmptyResult(new GenericResultMetaObject(serverInfo: $upstream)),
            )],
            serverInfo: new Implementation(name: 'test-server', version: '1.0.0'),
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame($upstream, self::sentResult($transport)->meta->serverInfo);
    }

    public function testDisclosesNoServerIdentityWhenNoneIsConfigured(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(requestHandlers: ['tools/list' => self::okHandler()]);

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        $result = self::sentResult($transport);
        self::assertNull($result->meta->serverInfo);
        self::assertArrayNotHasKey('_meta', $result->toArray());
    }

    public function testRequestPastTheInFlightCapIsShedAsOverloaded(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(requestHandlers: ['tools/list' => self::okHandler()], maxInFlight: 1);

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::toolsListEnvelope(2), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(2, $transport->sent);
        $shed = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $shed);
        self::assertSame(2, $shed->id?->id, 'The shed response must answer the request that was refused.');
        self::assertSame(SdkErrorCode::Overloaded->value, $shed->error->code);
        self::assertSame('Server overloaded', $shed->error->message);
    }

    public function testSubscriptionsListenIsAdmittedPastTheInFlightCap(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => self::okHandler(),
                'subscriptions/listen' => self::okHandler(),
            ],
            maxInFlight: 1,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::subscriptionsListenEnvelope(2), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(2, $transport->sent);
        $admitted = $transport->sent[1]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $admitted);
        self::assertSame(2, $admitted->id->id, 'The admitted response must answer the listen, not the request that saturated the cap.');
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[0]['message']);
    }

    public function testListensArrivingFasterThanTheLoopStartsThemAreShed(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['subscriptions/listen' => self::okHandler()],
            maxInFlight: 1,
        );

        $dispatcher->dispatch(self::subscriptionsListenEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::subscriptionsListenEnvelope(2), $transport, new ReceiveContext());

        self::assertCount(1, $transport->sent, 'The second listen is refused before its coroutine is spawned.');
        $shed = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $shed);
        self::assertSame(2, $shed->id?->id);
        self::assertSame(SdkErrorCode::Overloaded->value, $shed->error->code);

        $dispatcher->flushPending();
    }

    public function testAListenParkedInItsHandlerReleasesItsBudgetSlot(): void
    {
        $parked = new DeferredFuture();
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['subscriptions/listen' => new ClosureRequestHandler(
                static function () use ($parked): Result {
                    $parked->getFuture()->await();

                    return new EmptyResult();
                },
            )],
            maxInFlight: 1,
        );

        foreach ([1, 2, 3] as $id) {
            $dispatcher->dispatch(self::subscriptionsListenEnvelope($id), $transport, new ReceiveContext());
            delay(0.0);
        }

        self::assertCount(0, $transport->sent, 'A listen already parked in its handler no longer occupies the backlog.');

        $parked->complete();
        $dispatcher->flushPending();
    }

    public function testCancellationIsAdmittedPastTheInFlightCap(): void
    {
        $cancelled = null;
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => self::okHandler()],
            notificationHandlers: [
                'notifications/cancelled' => new ClosureNotificationHandler(static function () use (&$cancelled): void {
                    $cancelled = true;
                }),
            ],
            maxInFlight: 1,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 1]],
            $transport,
            new ReceiveContext(),
        );

        $dispatcher->flushPending();

        self::assertTrue($cancelled, 'The one notification that frees a slot must not be shed by the cap it would relieve.');
    }

    public function testACancellationNamingNothingInFlightIsShedAtTheCap(): void
    {
        $cancelled = 0;
        $logger = new ArrayLogger();
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => self::okHandler()],
            notificationHandlers: [
                'notifications/cancelled' => new ClosureNotificationHandler(static function () use (&$cancelled): void {
                    ++$cancelled;
                }),
            ],
            logger: $logger,
            maxInFlight: 1,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::cancelEnvelope(99), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame(0, $cancelled, 'A cancellation that frees nothing must meet the cap.');
        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Shed {count} notification(s) so far. The server is at its in-flight dispatch cap.',
        );
        self::assertCount(1, $matches);
        self::assertSame(['count' => 1], $matches[0]['context']);
    }

    public function testOnlyTheFirstCancellationNamingARequestIsAdmittedAtTheCap(): void
    {
        $cancelled = 0;
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => self::okHandler()],
            notificationHandlers: [
                'notifications/cancelled' => new ClosureNotificationHandler(static function () use (&$cancelled): void {
                    ++$cancelled;
                }),
            ],
            maxInFlight: 1,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::cancelEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::cancelEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame(1, $cancelled, 'Each request in flight funds the exemption once.');
    }

    public function testACancellationBelowTheCapLeavesTheCancelToItsHandler(): void
    {
        /** @var DeferredFuture<null> $parked */
        $parked = new DeferredFuture();
        $observed = null;
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => new ClosureRequestHandler(
                static function ($request, AbstractContext $context) use ($parked, &$observed): Result {
                    $observed = $context->cancellation;
                    $parked->getFuture()->await();

                    return new EmptyResult();
                },
            )],
            notificationHandlers: ['notifications/cancelled' => new ClosureNotificationHandler(static function (): void {})],
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        delay(0.0);
        $dispatcher->dispatch(self::cancelEnvelope(1), $transport, new ReceiveContext());
        delay(0.0);

        self::assertInstanceOf(Cancellation::class, $observed);

        self::assertFalse($observed->isRequested(), 'Below the cap the registered handler owns the cancel, and this one declined it.');

        $parked->complete(null);
        $dispatcher->flushPending();
    }

    public function testAnAdmittedCancellationTakesEffectOnAdmission(): void
    {
        /** @var DeferredFuture<null> $parked */
        $parked = new DeferredFuture();
        $observed = null;
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => new ClosureRequestHandler(
                static function ($request, AbstractContext $context) use ($parked, &$observed): Result {
                    $observed = $context->cancellation;
                    $parked->getFuture()->await();

                    return new EmptyResult();
                },
            )],
            notificationHandlers: ['notifications/cancelled' => new ClosureNotificationHandler(static function (): void {})],
            maxInFlight: 1,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        delay(0.0);
        $dispatcher->dispatch(self::cancelEnvelope(1), $transport, new ReceiveContext());

        self::assertInstanceOf(Cancellation::class, $observed);

        self::assertTrue($observed->isRequested(), 'Admission past the cap is funded by the cancel itself.');

        $parked->complete(null);
        $dispatcher->flushPending();
    }

    public function testSubscriptionsListenIsStillShedByAServerThatDoesNotServeIt(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(requestHandlers: ['tools/list' => self::okHandler()], maxInFlight: 1);

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::subscriptionsListenEnvelope(2), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(2, $transport->sent);
        $shed = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $shed);
        self::assertSame(2, $shed->id?->id);
        self::assertSame(SdkErrorCode::Overloaded->value, $shed->error->code);
    }

    public function testAShedRequestLeavesItsIdFreeForARetry(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(requestHandlers: ['tools/list' => self::okHandler()], maxInFlight: 1);

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::toolsListEnvelope(2), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        $dispatcher->dispatch(self::toolsListEnvelope(2), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(3, $transport->sent);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[2]['message']);
    }

    public function testRequestsUpToTheCapAreDispatchedNormally(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(requestHandlers: ['tools/list' => self::okHandler()], maxInFlight: 2);

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::toolsListEnvelope(2), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(2, $transport->sent);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[0]['message']);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[1]['message']);
    }

    public function testNoCapIsAppliedByDefault(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(requestHandlers: ['tools/list' => self::okHandler()]);

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::toolsListEnvelope(2), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(2, $transport->sent);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[0]['message']);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[1]['message']);
    }

    public function testNotificationPastTheInFlightCapIsShedWithoutAResponse(): void
    {
        $handled = false;
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => self::okHandler()],
            notificationHandlers: [
                'notifications/progress' => new ClosureNotificationHandler(static function () use (&$handled): void {
                    $handled = true;
                }),
            ],
            logger: $logger,
            maxInFlight: 1,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => 'p-1', 'progress' => 1.0]],
            $transport,
            new ReceiveContext(),
        );

        $dispatcher->flushPending();

        self::assertFalse($handled, 'A shed notification must not reach its handler.');
        self::assertCount(1, $transport->sent, 'JSON-RPC 2.0 §4.1 forbids answering a notification.');
        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Shed {count} notification(s) so far. The server is at its in-flight dispatch cap.',
        );
        self::assertCount(1, $matches);
        self::assertSame(['count' => 1], $matches[0]['context']);
    }

    public function testSecondRequestWithSameInFlightIdIsRejectedSynchronouslyWithInvalidRequest(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => self::okHandler()],
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->flushPending();

        self::assertCount(2, $transport->sent);

        self::assertInstanceOf(JsonRpcErrorResponse::class, $transport->sent[0]['message']);
        self::assertSame(1, $transport->sent[0]['message']->id?->id);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $transport->sent[0]['message']->error->code);
        self::assertSame(
            'Inbound request id is already pending on this session.',
            $transport->sent[0]['message']->error->message,
        );

        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[1]['message']);
        self::assertSame(1, $transport->sent[1]['message']->id->id);
    }

    public function testIdsAreReleasedAfterTheHandlerCompletesSoSequentialReuseSucceeds(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => self::okHandler()],
        );

        $dispatcher->dispatch(self::toolsListEnvelope('x'), $transport, new ReceiveContext());
        $dispatcher->flushPending();
        $dispatcher->dispatch(self::toolsListEnvelope('x'), $transport, new ReceiveContext());
        $dispatcher->flushPending();

        self::assertCount(2, $transport->sent);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[0]['message']);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[1]['message']);
    }

    public function testIdIsReleasedAfterAThrowingHandlerSoTheSameIdCanBeReused(): void
    {
        $calls = 0;
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static function () use (&$calls): Result {
                        if (1 === ++$calls) {
                            throw new \RuntimeException('boom');
                        }

                        return new EmptyResult();
                    },
                ),
            ],
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->flushPending();
        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());
        $dispatcher->flushPending();

        self::assertCount(2, $transport->sent);

        self::assertInstanceOf(JsonRpcErrorResponse::class, $transport->sent[0]['message']);
        self::assertSame(ProtocolErrorCode::InternalError->value, $transport->sent[0]['message']->error->code);

        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[1]['message']);
        self::assertSame(1, $transport->sent[1]['message']->id->id);
    }

    public function testErrorResponseUsesExceptionRequestIdWhenSetEvenIfDifferentFromIncomingRequestId(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static fn() => throw new ResourceNotFoundException('file:///x', new RequestId(id: 'explicit-id')),
                ),
            ],
        );

        $dispatcher->dispatch(self::toolsListEnvelope('incoming-id'), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame('explicit-id', $message->id?->id);
    }

    public function testHandlerThrowingMcpExceptionTranslatesToTypedErrorResponse(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static fn() => throw new ResourceNotFoundException('file:///missing'),
                ),
            ],
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::InvalidParams->value, $message->error->code);
        self::assertSame(1, $message->id?->id);
        self::assertSame(['uri' => 'file:///missing'], $message->error->toArray()['data'] ?? null);
        $context = $transport->sent[0]['context'];
        self::assertInstanceOf(SendContext::class, $context);
        self::assertTrue($context->fromHandler);
    }

    public function testHandlerThrowingNonMcpExceptionLogsAndReturnsGenericInternalError(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static fn() => throw new \RuntimeException('mysql://root:hunter2@db-prod:3306 unreachable'),
                ),
            ],
            logger: $logger,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::InternalError->value, $message->error->code);
        self::assertSame('Internal error', $message->error->message);
        self::assertStringNotContainsString('mysql://', $message->error->message);
        self::assertStringNotContainsString('hunter2', $message->error->message);
        $context = $transport->sent[0]['context'];
        self::assertInstanceOf(SendContext::class, $context);
        self::assertTrue($context->fromHandler);

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Uncaught request handler exception.');
        self::assertCount(1, $matches);
        self::assertSame('tools/list', $matches[0]['context']['method'] ?? null);
        $logged = $matches[0]['context']['exception'] ?? null;
        self::assertInstanceOf(\RuntimeException::class, $logged);
        self::assertSame('mysql://root:hunter2@db-prod:3306 unreachable', $logged->getMessage());
    }

    public function testHandlerReturningMisroutedResultReturnsInternalError(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static fn(): Result => InputRequiredResult::fromArray([
                        'resultType' => 'input_required',
                        'requestState' => 'tok',
                    ]),
                ),
            ],
            logger: $logger,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::InternalError->value, $message->error->code);

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Uncaught request handler exception.');
        self::assertCount(1, $matches);
        $logged = $matches[0]['context']['exception'] ?? null;
        self::assertInstanceOf(\InvalidArgumentException::class, $logged);
        self::assertSame(
            \sprintf('Handler for "tools/list" returned %s, which is not a valid result for that method.', InputRequiredResult::class),
            $logged->getMessage(),
        );
    }

    public function testTransportSendFailureIsLoggedRatherThanCrashingTheLoop(): void
    {
        $transport = new RecordingTransport();
        $transport->sendError = new \RuntimeException('write failed');
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => self::okHandler()],
            logger: $logger,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Failed to deliver response to transport.');
        self::assertCount(1, $matches);
        self::assertSame('tools/list', $matches[0]['context']['method'] ?? null);
        self::assertInstanceOf(\RuntimeException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testResultResponseSendFailingWithClosedTransportLogsInfoOnly(): void
    {
        $transport = new RecordingTransport();
        $transport->sendError = new TransportAlreadyClosedException('send');
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => self::okHandler()],
            logger: $logger,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR), 'Closed transport must not produce error-level logs.');
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Skipping response delivery. Transport is closed.');
        self::assertCount(1, $matches);
        self::assertSame('tools/list', $matches[0]['context']['method'] ?? null);
        self::assertInstanceOf(TransportAlreadyClosedException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testHandlerPropagatingTransportClosedSkipsInternalErrorFollowUp(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static fn() => throw new TransportAlreadyClosedException('send'),
                ),
            ],
            logger: $logger,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame([], $transport->sent, 'A closed transport must not receive an InternalError follow-up.');
        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Skipping response delivery. Transport is closed.');
        self::assertCount(1, $matches);
        self::assertSame('tools/list', $matches[0]['context']['method'] ?? null);
        self::assertInstanceOf(TransportAlreadyClosedException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testProtocolExceptionWithClosedTransportLogsInfoNotError(): void
    {
        $transport = new RecordingTransport();
        $transport->sendError = new TransportAlreadyClosedException('send');
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static fn() => throw new ResourceNotFoundException('file:///missing'),
                ),
            ],
            logger: $logger,
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Skipping response delivery. Transport is closed.');
        self::assertCount(1, $matches);
        self::assertSame('tools/list', $matches[0]['context']['method'] ?? null);
    }

    public function testRequestHandlerReceivesContextWithRequestScopedSender(): void
    {
        $transport = new RecordingTransport();

        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static function ($req, $ctx): EmptyResult {
                        \assert($ctx instanceof ServerContext);

                        $ctx->reportProgress(0.5);

                        return new EmptyResult();
                    },
                ),
            ],
        );

        $envelope = [
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'tools/list',
            'params' => ['_meta' => RequestMetaObjectFactory::shape(progressToken: new ProgressToken(token: 'tok-1'))],
        ];

        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        $dispatcher->flushPending();

        $progressSend = null;

        foreach ($transport->sent as $entry) {
            if ($entry['message'] instanceof ProgressNotification) {
                $progressSend = $entry;

                break;
            }
        }

        self::assertNotNull($progressSend);
        self::assertSame(99, $progressSend['context']?->relatedRequestId?->id);
    }

    public function testRequestHandlerReceivesTheThreadedReceiveContext(): void
    {
        $transport = new RecordingTransport();
        $receiveContext = new ReceiveContext();
        $captured = null;

        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static function ($req, $ctx) use (&$captured): EmptyResult {
                        \assert($ctx instanceof ServerContext);

                        $captured = $ctx->receiveContext;

                        return new EmptyResult();
                    },
                ),
            ],
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, $receiveContext);

        $dispatcher->flushPending();

        self::assertSame($receiveContext, $captured);
    }

    public function testNotificationWithNoRegisteredHandlerIsSilentlyDropped(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $dispatcher->dispatch(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => 1],
            ],
            $transport,
            new ReceiveContext(),
        );

        $dispatcher->flushPending();

        self::assertSame([], $transport->sent);
        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));
    }

    public function testNotificationHandlerThrowingIsLoggedNotResponded(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            notificationHandlers: [
                'notifications/cancelled' => new ClosureNotificationHandler(
                    static fn() => throw new \RuntimeException('handler boom'),
                ),
            ],
            logger: $logger,
        );

        $dispatcher->dispatch(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => 1],
            ],
            $transport,
            new ReceiveContext(),
        );

        $dispatcher->flushPending();

        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Uncaught notification handler exception.');
        self::assertCount(1, $matches);
        self::assertSame('notifications/cancelled', $matches[0]['context']['method'] ?? null);
        self::assertInstanceOf(\RuntimeException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testNotificationIsDispatchedToRegisteredHandler(): void
    {
        $invocations = 0;
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            notificationHandlers: [
                'notifications/cancelled' => new ClosureNotificationHandler(
                    static function () use (&$invocations): void { ++$invocations; },
                ),
            ],
        );

        $dispatcher->dispatch(
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/cancelled',
                'params' => ['requestId' => 1],
            ],
            $transport,
            new ReceiveContext(),
        );

        $dispatcher->flushPending();

        self::assertSame(1, $invocations);
        self::assertSame([], $transport->sent);
    }

    public function testFlushPendingWithNothingScheduledIsANoOp(): void
    {
        $dispatcher = self::buildDispatcher();

        $dispatcher->flushPending();

        $this->expectNotToPerformAssertions();
    }

    public function testFlushPendingDrainsAnInFlightRequestDispatchBeforeReturning(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => self::okHandler()],
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $message);
        self::assertSame(1, $message->id->id);
    }

    public function testFlushPendingDrainsAnInFlightNotificationDispatchBeforeReturning(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            notificationHandlers: [
                'notifications/cancelled' => new ClosureNotificationHandler(
                    static fn() => throw new \RuntimeException('handler ran'),
                ),
            ],
            logger: $logger,
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 1]],
            $transport,
            new ReceiveContext(),
        );

        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));

        $dispatcher->flushPending();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Uncaught notification handler exception.');
        self::assertCount(1, $matches);
    }

    public function testAParseFailureLogsNothingOfThePeerEnvelopeBeyondTheException(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => null, 'reason' => str_repeat('a', 5_000)."\x1b[2K"],
        ], $transport, new ReceiveContext());

        $dispatcher->flushPending();

        $matches = $logger->recordsMatching(
            LogLevel::INFO,
            'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).',
        );
        self::assertCount(1, $matches);
        self::assertSame(['exception'], array_keys($matches[0]['context']), 'The peer envelope must not ride the log record.');
    }

    /**
     * @param array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>> $requestHandlers
     * @param array<non-empty-string, NotificationHandlerInterface<non-empty-string>>                   $notificationHandlers
     * @param null|int<1, max>                                                                          $maxInFlight
     */
    private static function buildDispatcher(
        array $requestHandlers = [],
        array $notificationHandlers = [],
        ?ArrayLogger $logger = null,
        ?JsonRpcMessageParser $parser = null,
        ?Implementation $serverInfo = null,
        ?int $maxInFlight = null,
        ?PendingInboundRequests $inboundRequests = null,
    ): ServerMessageDispatcher {
        return new ServerMessageDispatcher(
            new HandlerRegistry($requestHandlers, RequestHandlerInterface::class, 'Request handler'),
            new HandlerRegistry($notificationHandlers, NotificationHandlerInterface::class, 'Notification handler'),
            logger: $logger ?? new ArrayLogger(),
            parser: $parser ?? new JsonRpcMessageParser(),
            serverInfo: $serverInfo,
            maxInFlight: $maxInFlight,
            inboundRequests: $inboundRequests ?? new PendingInboundRequests(),
        );
    }

    /**
     * @param \Closure(JsonRpcRequest<non-empty-string>, AbstractContext): Result $handler
     *
     * @return array{ServerMessageDispatcher, RecordingTransport, ArrayLogger}
     */
    private static function buildCancellableDispatcher(\Closure $handler): array
    {
        $inboundRequests = new PendingInboundRequests();
        $logger = new ArrayLogger();

        return [
            self::buildDispatcher(
                requestHandlers: ['tools/list' => new ClosureRequestHandler($handler)],
                notificationHandlers: [
                    'notifications/cancelled' => new CancelledNotificationHandler($inboundRequests, $logger),
                ],
                logger: $logger,
                inboundRequests: $inboundRequests,
            ),
            new RecordingTransport(),
            $logger,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cancelEnvelope(int|string $requestId): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => $requestId],
        ];
    }

    private static function sentResult(RecordingTransport $transport): Result
    {
        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];

        self::assertInstanceOf(JsonRpcResultResponse::class, $message);

        return $message->result;
    }

    /**
     * @return RequestHandlerInterface<non-empty-string, Result, AbstractContext>
     */
    private static function okHandler(): RequestHandlerInterface
    {
        return new ClosureRequestHandler(static fn() => new EmptyResult());
    }

    /**
     * @return array<string, mixed>
     */
    private static function subscriptionsListenEnvelope(int|string $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'subscriptions/listen',
            'params' => [
                '_meta' => RequestMetaObjectFactory::shape(),
                'notifications' => ['toolsListChanged' => true],
            ],
        ];
    }

    /**
     * @param null|non-empty-string $protocolVersion
     *
     * @return array<string, mixed>
     */
    private static function toolsListEnvelope(int|string $id, ?string $protocolVersion = null): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/list',
            'params' => ['_meta' => RequestMetaObjectFactory::shape(protocolVersion: $protocolVersion)],
        ];
    }

    /**
     * @param null|non-empty-string $protocolVersion
     *
     * @return array<string, mixed>
     */
    private static function discoverEnvelope(int|string $id, ?string $protocolVersion = null): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape(protocolVersion: $protocolVersion)],
        ];
    }
}
