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

use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Exception\MethodMisroutedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\HandlerRegistry;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Implementation;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\DiscoverResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\ServerCapabilities;
use Nexus\Mcp\Server\Dispatch\ServerMessageDispatcher;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\Handler\Request\DiscoverRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

/**
 * @internal
 */
#[CoversClass(ServerMessageDispatcher::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ServerMessageDispatcherTest extends TestCase
{
    public function testInboundResultResponseEnvelopeIsLoggedAndDropped(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'result' => []];
        $dispatcher->dispatch($envelope, $transport);

        EventLoop::run();

        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Discarding response envelope (server has no outbound-request correlation).',
        );
        self::assertCount(1, $matches);
        self::assertSame(['envelope' => $envelope], $matches[0]['context']);
    }

    public function testInboundErrorResponseEnvelopeIsLoggedAndDropped(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32603, 'message' => 'oops']];
        $dispatcher->dispatch($envelope, $transport);

        EventLoop::run();

        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Discarding response envelope (server has no outbound-request correlation).',
        );
        self::assertCount(1, $matches);
        self::assertSame(['envelope' => $envelope], $matches[0]['context']);
    }

    public function testMalformedResponseEnvelopeIsDroppedNotAnsweredWithError(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'result' => 'opaque-string'];
        $dispatcher->dispatch($envelope, $transport);

        EventLoop::run();

        self::assertSame([], $transport->sent, 'A response envelope must not provoke another response, even when malformed.');
        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Discarding response envelope (server has no outbound-request correlation).',
        );
        self::assertCount(1, $matches);
        self::assertSame(['envelope' => $envelope], $matches[0]['context']);
        self::assertSame([], $logger->messagesAtLevel(LogLevel::INFO), 'Response envelopes must not fall through to the notification-drop log.');
    }

    public function testParseFailureSendsErrorResponseWithRecoveredId(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher();

        $dispatcher->dispatch(['jsonrpc' => '1.0', 'id' => 7, 'method' => 'tools/list'], $transport);

        EventLoop::run();

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
        $dispatcher->dispatch($envelope, $transport);

        EventLoop::run();

        self::assertSame([], $transport->sent, 'JSON-RPC 2.0 §4.1 forbids responses to notifications.');
        $matches = $logger->recordsMatching(
            LogLevel::INFO,
            'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).',
        );
        self::assertCount(1, $matches);
        self::assertSame($envelope, $matches[0]['context']['envelope'] ?? null);
        self::assertInstanceOf(AbstractJsonRpcProtocolException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testRequestMethodSentAsNotificationLogsWarnAndSendsInvalidRequestWithNullId(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'method' => 'tools/list'];
        $dispatcher->dispatch($envelope, $transport);

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertNull($message->id, 'Misrouted request envelope carried no id, so the response uses null per JSON-RPC 2.0 §5.');
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $message->error->code);
        self::assertStringContainsString('"tools/list"', $message->error->message);

        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Rejecting envelope whose method was sent under the wrong JSON-RPC shape.',
        );
        self::assertCount(1, $matches);
        self::assertSame($envelope, $matches[0]['context']['envelope'] ?? null);
        self::assertInstanceOf(MethodMisroutedException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testNotificationMethodSentAsRequestIsDroppedAndLoggedNotAnsweredWithError(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'notifications/cancelled'];
        $dispatcher->dispatch($envelope, $transport);

        EventLoop::run();

        self::assertSame([], $transport->sent, 'JSON-RPC 2.0 §4.1 forbids responses to notifications, even when the envelope carries an id.');

        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Rejecting envelope whose method was sent under the wrong JSON-RPC shape.',
        );
        self::assertCount(1, $matches);
        self::assertSame($envelope, $matches[0]['context']['envelope'] ?? null);
        self::assertInstanceOf(MethodMisroutedException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testUnknownNotificationMethodIsDroppedAndLoggedNotAnsweredWithError(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(logger: $logger);

        $envelope = ['jsonrpc' => '2.0', 'method' => 'notifications/vendor/unknown'];
        $dispatcher->dispatch($envelope, $transport);

        EventLoop::run();

        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(
            LogLevel::INFO,
            'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).',
        );
        self::assertCount(1, $matches);
        self::assertSame($envelope, $matches[0]['context']['envelope'] ?? null);
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
        $dispatcher->dispatch($envelope, $transport);

        EventLoop::run();

        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(
            LogLevel::INFO,
            'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).',
        );
        self::assertCount(1, $matches);
        self::assertSame($envelope, $matches[0]['context']['envelope'] ?? null);
    }

    public function testRequestForUnknownMethodReturnsMethodNotFoundError(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher();

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'id' => 'req-1', 'method' => 'vendor/unknown'],
            $transport,
        );

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame('req-1', $message->id?->id);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $message->error->code);
    }

    public function testRequestForSpecMethodWithNoRegisteredHandlerReturnsMethodNotFoundError(): void
    {
        // Parser accepts the method (it is in `JsonRpcMethodRegistry`), but the
        // dispatcher's handler registry is empty, so the throw expression on the
        // request path fires `MethodNotFoundException` and rides the same
        // protocol-error catch-arm to a `MethodNotFound` error response.
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher();

        $dispatcher->dispatch(self::toolsListEnvelope('req-2'), $transport);

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame('req-2', $message->id?->id);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $message->error->code);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    #[DataProvider('provideServerRejectsServerToClientMethodWithMethodNotFoundCases')]
    public function testServerRejectsServerToClientMethodWithMethodNotFound(string $expectedId, array $envelope): void
    {
        // The parser accepts these methods (they live in the registry), but they are
        // server-to-client requests with null or standalone params. The dispatcher must
        // answer MethodNotFound rather than read `->meta` and silently drop the response.
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher();

        $dispatcher->dispatch($envelope, $transport);

        EventLoop::run();

        self::assertCount(1, $transport->sent, 'A server-bound server-to-client method must draw exactly one response, not a silent drop.');
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame($expectedId, $message->id?->id);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $message->error->code);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function provideServerRejectsServerToClientMethodWithMethodNotFoundCases(): iterable
    {
        yield 'roots/list (null params)' => [
            'r-1',
            ['jsonrpc' => '2.0', 'id' => 'r-1', 'method' => 'roots/list'],
        ];

        yield 'sampling/createMessage (standalone params)' => [
            'r-2',
            [
                'jsonrpc' => '2.0',
                'id' => 'r-2',
                'method' => 'sampling/createMessage',
                'params' => [
                    'maxTokens' => 100,
                    'messages' => [['role' => 'user', 'content' => ['text' => 'hi', 'type' => 'text']]],
                ],
            ],
        ];

        yield 'elicitation/create (standalone params)' => [
            'r-3',
            [
                'jsonrpc' => '2.0',
                'id' => 'r-3',
                'method' => 'elicitation/create',
                'params' => [
                    'mode' => 'form',
                    'message' => 'Pick',
                    'requestedSchema' => [
                        'type' => 'object',
                        'properties' => ['x' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
    }

    public function testServerToClientMethodIsRejectedByDirectionEvenWhenAHandlerIsRegistered(): void
    {
        // Registering a server-to-client handler is a misconfiguration. The dispatcher must
        // reject by direction before the handler runs, not invoke it.
        $invoked = false;
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'roots/list' => new ClosureRequestHandler(
                    static function () use (&$invoked): Result {
                        $invoked = true;

                        return new EmptyResult();
                    },
                ),
            ],
        );

        $dispatcher->dispatch(
            [
                'jsonrpc' => '2.0',
                'id' => 'r-1',
                'method' => 'roots/list',
                'params' => ['_meta' => RequestMetaObjectFactory::shape()],
            ],
            $transport,
        );

        EventLoop::run();

        self::assertFalse($invoked, 'A server-to-client method must be rejected by direction before any registered handler runs.');
        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame('r-1', $message->id?->id);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $message->error->code);
    }

    public function testSuccessfulRequestSendsResultResponse(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['server/discover' => new DiscoverRequestHandler(
                new Implementation('test-server', '1.0.0'),
                new ServerCapabilities(),
            )],
        );

        $dispatcher->dispatch(self::discoverEnvelope(1), $transport);

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $message);
        self::assertSame(1, $message->id->id);
        self::assertInstanceOf(DiscoverResult::class, $message->result);
    }

    public function testSecondRequestWithSameInFlightIdIsRejectedSynchronouslyWithInvalidRequest(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['tools/list' => self::okHandler()],
        );

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport);
        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport);
        EventLoop::run();

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

        $dispatcher->dispatch(self::toolsListEnvelope('x'), $transport);
        EventLoop::run();
        $dispatcher->dispatch(self::toolsListEnvelope('x'), $transport);
        EventLoop::run();

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

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport);
        EventLoop::run();
        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport);
        EventLoop::run();

        self::assertCount(2, $transport->sent);

        // First handler threw, so the id must be released despite the early return.
        self::assertInstanceOf(JsonRpcErrorResponse::class, $transport->sent[0]['message']);
        self::assertSame(ProtocolErrorCode::InternalError->value, $transport->sent[0]['message']->error->code);

        // Reusing the same id succeeds, proving the `finally` released it on the throw path.
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[1]['message']);
        self::assertSame(1, $transport->sent[1]['message']->id->id);
    }

    public function testErrorResponseUsesExceptionRequestIdWhenSetEvenIfDifferentFromIncomingRequestId(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static fn() => throw new ResourceNotFoundException('file:///x', new RequestId('explicit-id')),
                ),
            ],
        );

        $dispatcher->dispatch(self::toolsListEnvelope('incoming-id'), $transport);

        EventLoop::run();

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

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport);

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::InvalidParams->value, $message->error->code);
        self::assertSame(1, $message->id?->id);
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

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport);

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::InternalError->value, $message->error->code);
        self::assertSame('Internal error', $message->error->message);
        self::assertStringNotContainsString('mysql://', $message->error->message);
        self::assertStringNotContainsString('hunter2', $message->error->message);

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Uncaught request handler exception.');
        self::assertCount(1, $matches);
        self::assertSame('tools/list', $matches[0]['context']['method'] ?? null);
        $logged = $matches[0]['context']['exception'] ?? null;
        self::assertInstanceOf(\RuntimeException::class, $logged);
        self::assertSame('mysql://root:hunter2@db-prod:3306 unreachable', $logged->getMessage());
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

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport);

        EventLoop::run();

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

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport);

        EventLoop::run();

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

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport);

        EventLoop::run();

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

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport);

        EventLoop::run();

        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Skipping response delivery. Transport is closed.');
        self::assertCount(1, $matches);
        self::assertSame('tools/list', $matches[0]['context']['method'] ?? null);
    }

    public function testRequestHandlerReceivesContextWithSessionIdAndRequestScopedSender(): void
    {
        $transport = new RecordingTransport(sessionId: 'sess-xyz');
        $captured = ['sessionId' => null];

        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => new ClosureRequestHandler(
                    static function ($req, $ctx) use (&$captured): EmptyResult {
                        \assert($ctx instanceof ServerContext);

                        $captured['sessionId'] = $ctx->sessionId;
                        $ctx->log(LoggingLevel::Info, 'hello');

                        return new EmptyResult();
                    },
                ),
            ],
        );

        $dispatcher->dispatch(self::toolsListEnvelope(99), $transport);

        EventLoop::run();

        self::assertSame('sess-xyz', $captured['sessionId']);

        // The notification emitted via $ctx->log() should be tagged with the
        // originating request id, proving the request-scoped sender binding.
        $logSend = null;

        foreach ($transport->sent as $entry) {
            if ($entry['message'] instanceof LoggingMessageNotification) {
                $logSend = $entry;

                break;
            }
        }

        self::assertNotNull($logSend);
        self::assertSame(99, $logSend['context']?->relatedRequestId?->id);
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
        );

        EventLoop::run();

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
        );

        EventLoop::run();

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
        );

        EventLoop::run();

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

        $dispatcher->dispatch(self::toolsListEnvelope(1), $transport);

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
        );

        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));

        $dispatcher->flushPending();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Uncaught notification handler exception.');
        self::assertCount(1, $matches);
    }

    /**
     * @param array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>> $requestHandlers
     * @param array<non-empty-string, NotificationHandlerInterface<non-empty-string>>                   $notificationHandlers
     */
    private static function buildDispatcher(
        array $requestHandlers = [],
        array $notificationHandlers = [],
        ?ArrayLogger $logger = null,
    ): ServerMessageDispatcher {
        return new ServerMessageDispatcher(
            new HandlerRegistry($requestHandlers, RequestHandlerInterface::class, 'Request handler'),
            new HandlerRegistry($notificationHandlers, NotificationHandlerInterface::class, 'Notification handler'),
            logger: $logger ?? new ArrayLogger(),
        );
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
    private static function toolsListEnvelope(int|string $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/list',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function discoverEnvelope(int|string $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ];
    }
}
