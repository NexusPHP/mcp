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
use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\NotificationHandlerRegistry;
use Nexus\Mcp\Core\Handler\Request\PingRequestHandler;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerRegistry;
use Nexus\Mcp\Core\Schema\Enum\LoggingLevel;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\Notification\LoggingMessageNotification;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Server\Dispatch\InitializationGate;
use Nexus\Mcp\Server\Dispatch\MessageDispatcher;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

/**
 * @internal
 */
#[CoversClass(MessageDispatcher::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class MessageDispatcherTest extends TestCase
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

        $dispatcher->dispatch(['jsonrpc' => '1.0', 'id' => 7, 'method' => 'ping'], $transport);

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

    public function testUnknownNotificationMethodIsDroppedAndLoggedNotAnsweredWithError(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(initialize: true, logger: $logger);

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
        $dispatcher = self::buildDispatcher(initialize: true, logger: $logger);

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
        $dispatcher = self::buildDispatcher(initialize: true);

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

    public function testNonInitRequestBeforeHandshakeIsRejected(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: [
                'tools/list' => self::okHandler(),
            ],
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            $transport,
        );

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $message->error->code);
        self::assertStringContainsString('tools/list', $message->error->message);
    }

    public function testSecondInitializeAfterHandshakeStartedIsRejectedWithAlreadyInitializedError(): void
    {
        $transport = new RecordingTransport();
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $dispatcher = self::buildDispatcher(
            gate: $gate,
            requestHandlers: [
                'initialize' => new ClosureRequestHandler(
                    static fn() => self::fail('Handler must not run for re-initialize attempt.'),
                ),
            ],
        );

        $dispatcher->dispatch(self::initializeEnvelope(), $transport);

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $message->error->code);
        self::assertStringContainsString('re-initialize', $message->error->message);
    }

    public function testSecondInitializeAfterFullHandshakeIsRejectedWithAlreadyInitializedError(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            initialize: true,
            requestHandlers: [
                'initialize' => new ClosureRequestHandler(
                    static fn() => self::fail('Handler must not run for re-initialize attempt.'),
                ),
            ],
        );

        $dispatcher->dispatch(self::initializeEnvelope(), $transport);

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $message->error->code);
        self::assertStringContainsString('re-initialize', $message->error->message);
    }

    public function testPingIsAllowedBeforeHandshake(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            requestHandlers: ['ping' => new PingRequestHandler()],
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            $transport,
        );

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[0]['message']);
    }

    public function testSuccessfulRequestSendsResultResponse(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            initialize: true,
            requestHandlers: ['ping' => new PingRequestHandler()],
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            $transport,
        );

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $message);
        self::assertSame(1, $message->id->id);
    }

    public function testErrorResponseUsesExceptionRequestIdWhenSetEvenIfDifferentFromIncomingRequestId(): void
    {
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            initialize: true,
            requestHandlers: [
                'ping' => new ClosureRequestHandler(
                    static fn() => throw new ResourceNotFoundException('file:///x', new RequestId('explicit-id')),
                ),
            ],
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'id' => 'incoming-id', 'method' => 'ping'],
            $transport,
        );

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
            initialize: true,
            requestHandlers: [
                'ping' => new ClosureRequestHandler(
                    static fn() => throw new ResourceNotFoundException('file:///missing'),
                ),
            ],
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            $transport,
        );

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
            initialize: true,
            requestHandlers: [
                'ping' => new ClosureRequestHandler(
                    static fn() => throw new \RuntimeException('mysql://root:hunter2@db-prod:3306 unreachable'),
                ),
            ],
            logger: $logger,
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            $transport,
        );

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
        self::assertSame('ping', $matches[0]['context']['method'] ?? null);
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
            initialize: true,
            requestHandlers: ['ping' => new PingRequestHandler()],
            logger: $logger,
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            $transport,
        );

        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Failed to deliver response to transport.');
        self::assertCount(1, $matches);
        self::assertSame('ping', $matches[0]['context']['method'] ?? null);
        self::assertInstanceOf(\RuntimeException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testRequestHandlerReceivesContextWithSessionIdAndRequestScopedSender(): void
    {
        $transport = new RecordingTransport(sessionId: 'sess-xyz');
        $captured = ['sessionId' => null];

        $dispatcher = self::buildDispatcher(
            initialize: true,
            requestHandlers: [
                'ping' => new ClosureRequestHandler(
                    static function ($req, $ctx) use (&$captured): EmptyResult {
                        $captured['sessionId'] = $ctx->sessionId;
                        $ctx->log(LoggingLevel::Info, 'hello');

                        return new EmptyResult();
                    },
                ),
            ],
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'id' => 99, 'method' => 'ping'],
            $transport,
        );

        EventLoop::run();

        self::assertSame('sess-xyz', $captured['sessionId']);

        // The notification emitted via $ctx->log() should be tagged with the
        // originating request id, proving the request-scoped sender wiring.
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

    public function testNotificationBeforeInitializeIsDroppedAndLoggedAtInfo(): void
    {
        $invocations = 0;
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            notificationHandlers: [
                'notifications/cancelled' => new ClosureNotificationHandler(
                    static function () use (&$invocations): void { ++$invocations; },
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

        self::assertSame(0, $invocations);
        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Dropping notification before client has completed initialize.');
        self::assertCount(1, $matches);
        self::assertSame(['method' => 'notifications/cancelled'], $matches[0]['context']);
    }

    public function testInitializedNotificationFlipsTheGateAndIsDispatchedToHandlerWhenInFlight(): void
    {
        $invocations = 0;
        $transport = new RecordingTransport();
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $dispatcher = self::buildDispatcher(
            gate: $gate,
            notificationHandlers: [
                'notifications/initialized' => new ClosureNotificationHandler(
                    static function () use (&$invocations): void { ++$invocations; },
                ),
            ],
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $transport,
        );

        EventLoop::run();

        self::assertTrue($gate->isInitialized());
        self::assertSame(1, $invocations);
    }

    public function testInitializedNotificationBeforeInitializeRequestIsDiscardedAndDoesNotFlipGate(): void
    {
        $invocations = 0;
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $gate = new InitializationGate();
        $dispatcher = self::buildDispatcher(
            gate: $gate,
            notificationHandlers: [
                'notifications/initialized' => new ClosureNotificationHandler(
                    static function () use (&$invocations): void { ++$invocations; },
                ),
            ],
            logger: $logger,
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $transport,
        );

        EventLoop::run();

        self::assertFalse($gate->isInitialized());
        self::assertSame(0, $invocations);
        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Discarding "notifications/initialized" received outside of an in-flight "initialize" handshake.',
        );
        self::assertCount(1, $matches);
        self::assertSame(['method' => 'notifications/initialized'], $matches[0]['context']);
    }

    public function testInitializedNotificationAfterFullHandshakeIsDiscardedAndDoesNotFireHandlerAgain(): void
    {
        $invocations = 0;
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $gate = new InitializationGate();
        $gate->markInitializeInFlight();
        $gate->markInitialized();
        $dispatcher = self::buildDispatcher(
            gate: $gate,
            notificationHandlers: [
                'notifications/initialized' => new ClosureNotificationHandler(
                    static function () use (&$invocations): void { ++$invocations; },
                ),
            ],
            logger: $logger,
        );

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $transport,
        );

        EventLoop::run();

        self::assertSame(0, $invocations);
        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(
            LogLevel::WARNING,
            'Discarding "notifications/initialized" received outside of an in-flight "initialize" handshake.',
        );
        self::assertCount(1, $matches);
    }

    public function testSuccessfulInitializeHandlerFlipsGateToInFlight(): void
    {
        $transport = new RecordingTransport();
        $gate = new InitializationGate();
        $dispatcher = self::buildDispatcher(
            gate: $gate,
            requestHandlers: [
                'initialize' => new ClosureRequestHandler(
                    static fn() => new EmptyResult(),
                ),
            ],
        );

        $dispatcher->dispatch(self::initializeEnvelope(), $transport);

        EventLoop::run();

        self::assertFalse($gate->isInitialized(), 'Gate should be in-flight, not initialized, before "notifications/initialized" arrives.');
        self::assertFalse($gate->allowsRequest('tools/list'), 'Non-init requests must remain rejected during in-flight state.');

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $transport,
        );

        EventLoop::run();

        self::assertTrue($gate->isInitialized());
        self::assertTrue($gate->allowsRequest('tools/list'));
    }

    public function testFailedInitializeHandlerDoesNotFlipGateToInFlight(): void
    {
        $transport = new RecordingTransport();
        $gate = new InitializationGate();
        $dispatcher = self::buildDispatcher(
            gate: $gate,
            requestHandlers: [
                'initialize' => new ClosureRequestHandler(
                    static fn() => throw new \RuntimeException('init blew up'),
                ),
            ],
        );

        $dispatcher->dispatch(self::initializeEnvelope(), $transport);

        EventLoop::run();

        self::assertFalse($gate->isInitialized());

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $transport,
        );

        EventLoop::run();

        self::assertFalse($gate->isInitialized(), 'Gate must not promote to initialized when the preceding "initialize" handler failed.');
    }

    public function testInitializeEnvelopeWithMalformedParamsLeavesGateAwaiting(): void
    {
        $transport = new RecordingTransport();
        $gate = new InitializationGate();
        $dispatcher = self::buildDispatcher(gate: $gate);

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
            $transport,
        );

        EventLoop::run();

        self::assertFalse($gate->isInitialized());

        $dispatcher->dispatch(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $transport,
        );

        EventLoop::run();

        self::assertFalse($gate->isInitialized(), 'Subsequent "notifications/initialized" must still be discarded when no valid "initialize" envelope has been received.');
    }

    public function testNotificationWithNoRegisteredHandlerIsSilentlyDropped(): void
    {
        $transport = new RecordingTransport();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(initialize: true, logger: $logger);

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
            initialize: true,
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

    /**
     * @param array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ServerContext>> $requestHandlers
     * @param array<non-empty-string, NotificationHandlerInterface<non-empty-string>>                   $notificationHandlers
     */
    private static function buildDispatcher(
        bool $initialize = false,
        array $requestHandlers = [],
        array $notificationHandlers = [],
        ?InitializationGate $gate = null,
        ?ArrayLogger $logger = null,
    ): MessageDispatcher {
        $gate ??= new InitializationGate();

        if ($initialize) {
            $gate->markInitializeInFlight();
            $gate->markInitialized();
        }

        return new MessageDispatcher(
            new RequestHandlerRegistry($requestHandlers),
            new NotificationHandlerRegistry($notificationHandlers),
            $gate,
            $logger ?? new ArrayLogger(),
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
    private static function initializeEnvelope(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
            ],
        ];
    }
}
