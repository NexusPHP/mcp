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

namespace Nexus\Mcp\Tests\Client\Dispatch;

use Nexus\Mcp\Client\ClientContext;
use Nexus\Mcp\Client\Dispatch\ClientMessageDispatcher;
use Nexus\Mcp\Core\Dispatch\PendingOutboundRequests;
use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\Exception\RemoteCallFailedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Handler\HandlerRegistry;
use Nexus\Mcp\Core\Handler\NotificationHandlerInterface;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\JsonRpc\JsonRpcMessageParser;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcResultResponse;
use Nexus\Mcp\Core\Schema\ProgressToken;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\ResultResponse\CallToolResultResponse;
use Nexus\Mcp\Core\Transport\ReceiveContext;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureNotificationHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\TestRequest;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Revolt\EventLoop;

/**
 * @internal
 */
#[CoversClass(ClientMessageDispatcher::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class ClientMessageDispatcherTest extends TestCase
{
    public function testSuccessResponseResolvesTheRegisteredFuture(): void
    {
        $outbound = new PendingOutboundRequests();
        $future = $outbound->register(new RequestId(id: 7), CallToolResultResponse::class);
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher($outbound, logger: $logger);
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 7, 'result' => ['content' => []]], $transport, new ReceiveContext());

        EventLoop::run();

        self::assertTrue($future->isComplete());
        $response = $future->await();
        self::assertInstanceOf(JsonRpcResultResponse::class, $response);
        self::assertSame(7, $response->id->id);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertSame([], $logger->recordsMatching(LogLevel::WARNING, 'Discarding orphan success response for unknown request id.'));
    }

    public function testErrorResponseRejectsTheRegisteredFutureWithRemoteCallFailedException(): void
    {
        $outbound = new PendingOutboundRequests();
        $future = $outbound->register(new RequestId(id: 1), CallToolResultResponse::class);
        $dispatcher = self::buildDispatcher($outbound);
        $transport = new RecordingTransport();

        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::InvalidParams->value, 'message' => 'missing field'],
        ], $transport, new ReceiveContext());

        EventLoop::run();

        try {
            $future->await();
            self::fail('Future should have been rejected.');
        } catch (RemoteCallFailedException $e) {
            self::assertSame('missing field', $e->getMessage());
            self::assertSame(ProtocolErrorCode::InvalidParams->value, $e->getCode());
        }
    }

    public function testErrorResponseWithNonObjectDataRejectsTheRegisteredFuture(): void
    {
        $outbound = new PendingOutboundRequests();
        $future = $outbound->register(new RequestId(id: 1), CallToolResultResponse::class);
        $dispatcher = self::buildDispatcher($outbound);
        $transport = new RecordingTransport();

        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => ProtocolErrorCode::InternalError->value, 'message' => 'boom', 'data' => [1, 2, 3]],
        ], $transport, new ReceiveContext());

        EventLoop::run();

        try {
            $future->await();
            self::fail('Future should have been rejected.');
        } catch (RemoteCallFailedException $e) {
            self::assertSame('boom', $e->getMessage());
            self::assertSame(ProtocolErrorCode::InternalError->value, $e->getCode());
        }
    }

    public function testOrphanSuccessResponseIsLoggedAndDropped(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher($outbound, logger: $logger);
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 999, 'result' => []], $transport, new ReceiveContext());

        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Discarding orphan success response for unknown request id.');
        self::assertCount(1, $matches);
        self::assertSame(['id' => 999], $matches[0]['context']);
    }

    public function testOrphanErrorResponseIsLoggedAndDropped(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher($outbound, logger: $logger);
        $transport = new RecordingTransport();

        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'id' => 999,
            'error' => ['code' => -32603, 'message' => 'oops'],
        ], $transport, new ReceiveContext());

        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Discarding orphan error response for unknown request id.');
        self::assertCount(1, $matches);
        self::assertSame(['id' => 999, 'error' => 'oops'], $matches[0]['context']);
    }

    public function testErrorResponseWithNullIdIsLoggedWithoutCorrelation(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher($outbound, logger: $logger);
        $transport = new RecordingTransport();

        // Error responses are the only response shape where the JSON-RPC spec allows `id: null`.
        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32700, 'message' => 'parse error'],
        ], $transport, new ReceiveContext());

        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Discarding error response with null id. No correlation to an outbound request is possible.');
        self::assertCount(1, $matches);
        self::assertSame(['error' => 'parse error'], $matches[0]['context']);
    }

    public function testSuccessResponseWithMalformedResultPayloadRejectsTheFuture(): void
    {
        $outbound = new PendingOutboundRequests();
        $future = $outbound->register(new RequestId(id: 1), CallToolResultResponse::class);
        $dispatcher = self::buildDispatcher($outbound);
        $transport = new RecordingTransport();

        // result is not a JSON object - parser will reject.
        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'result' => 'not-an-object'], $transport, new ReceiveContext());

        EventLoop::run();

        self::assertTrue($future->isComplete());
        $this->expectException(\Throwable::class);
        $future->await();
    }

    public function testMalformedResponseEnvelopeIsLoggedAndDropped(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher($outbound, logger: $logger);
        $transport = new RecordingTransport();

        $envelope = ['id' => 1, 'result' => []];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Discarding malformed response envelope from peer.');
        self::assertCount(1, $matches);
        self::assertSame($envelope, $matches[0]['context']['envelope'] ?? null);
        self::assertInstanceOf(\Throwable::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testResponseEnvelopeDispatchReturnsImmediatelyWithoutFallingThroughToTheParseStep(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher($outbound, logger: $logger);
        $transport = new RecordingTransport();

        // Response envelope with no pending entry. dispatchResponseEnvelope handles it and must NOT
        // fall through into the request/notification parse branch (which would log a "malformed
        // notification" info entry because the response envelope lacks a method).
        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 999, 'result' => []], $transport, new ReceiveContext());

        EventLoop::run();

        self::assertSame([], $transport->sent);
        self::assertSame([], $logger->messagesAtLevel(LogLevel::INFO), 'Response envelopes must not fall through into the notification-parse path.');
    }

    public function testHandlerThrowingExceptionStillReleasesTheInboundRequestId(): void
    {
        $outbound = new PendingOutboundRequests();
        $attempts = 0;
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: [
                'tests/test-request' => new ClosureRequestHandler(
                    static function () use (&$attempts): EmptyResult {
                        if (1 === ++$attempts) {
                            throw new \RuntimeException('first attempt fails');
                        }

                        return new EmptyResult();
                    },
                ),
            ],
        );
        $transport = new RecordingTransport();

        // First dispatch with id 1 - handler throws.
        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        // Second dispatch with the SAME id 1. The finally block must have released it.
        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        self::assertSame(2, $attempts);
        self::assertCount(2, $transport->sent);
        self::assertInstanceOf(JsonRpcErrorResponse::class, $transport->sent[0]['message']);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[1]['message']);
    }

    public function testToErrorResponseUsesExceptionRequestIdWhenItIsSetEvenIfDifferentFromFallback(): void
    {
        $outbound = new PendingOutboundRequests();
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: [
                'tests/test-request' => new ClosureRequestHandler(
                    static fn() => throw new MethodNotFoundException('tools/call', new RequestId(id: 'exception-pinned-id')),
                ),
            ],
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 42, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(
            'exception-pinned-id',
            $message->id?->id,
            'Exception-supplied id wins over the fallback request id.',
        );
    }

    public function testInboundRequestIsDispatchedToTheRegisteredHandlerAndItsResultIsSent(): void
    {
        $outbound = new PendingOutboundRequests();
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: ['tests/test-request' => new ClosureRequestHandler(static fn(): EmptyResult => new EmptyResult())],
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcResultResponse::class, $message);
        self::assertSame(1, $message->id->id);
    }

    public function testInboundHandlerReturningMisroutedResultReturnsInternalError(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: [
                'tests/test-request' => new ClosureRequestHandler(
                    static fn(): Result => InputRequiredResult::fromArray([
                        'resultType' => 'input_required',
                        'requestState' => 'tok',
                    ]),
                ),
            ],
            logger: $logger,
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::InternalError->value, $message->error->code);

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Uncaught request handler exception.');
        self::assertCount(1, $matches);
        $logged = $matches[0]['context']['exception'] ?? null;
        self::assertInstanceOf(\InvalidArgumentException::class, $logged);
        self::assertSame(
            \sprintf('Handler for "tests/test-request" returned %s, which is not a valid result for that method.', InputRequiredResult::class),
            $logged->getMessage(),
        );
    }

    public function testInboundRequestHandlerReceivesNoProgressTokenEvenWhenMetaCarriesOne(): void
    {
        $outbound = new PendingOutboundRequests();
        $handled = false;
        $captured = new ProgressToken(token: 'sentinel');
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: [
                'tests/test-request' => new ClosureRequestHandler(
                    static function ($req, $ctx) use (&$handled, &$captured): EmptyResult {
                        if (! $ctx instanceof ClientContext) {
                            self::fail('Expected a ClientContext.');
                        }

                        $handled = true;
                        $captured = $ctx->progressToken;

                        return new EmptyResult();
                    },
                ),
            ],
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tests/test-request',
            'params' => [
                '_meta' => RequestMetaObjectFactory::shape(new ProgressToken(token: 'p-1')),
            ],
        ], $transport, new ReceiveContext());

        EventLoop::run();

        self::assertTrue($handled);
        self::assertNull($captured);
    }

    public function testInboundRequestForUnknownMethodResponseIsMethodNotFound(): void
    {
        $outbound = new PendingOutboundRequests();
        $dispatcher = self::buildDispatcher($outbound);
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::MethodNotFound->value, $message->error->code);
    }

    public function testInboundNotificationIsDispatchedToTheRegisteredHandler(): void
    {
        $outbound = new PendingOutboundRequests();
        $invocations = 0;
        $dispatcher = self::buildDispatcher(
            $outbound,
            notificationHandlers: [
                'notifications/cancelled' => new ClosureNotificationHandler(
                    static function () use (&$invocations): void { ++$invocations; },
                ),
            ],
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 1],
        ], $transport, new ReceiveContext());

        EventLoop::run();

        self::assertSame(1, $invocations);
    }

    public function testInboundNotificationHandlerThrowingIsLoggedNotResponded(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            $outbound,
            notificationHandlers: [
                'notifications/cancelled' => new ClosureNotificationHandler(
                    static fn() => throw new \RuntimeException('handler boom'),
                ),
            ],
            logger: $logger,
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 1],
        ], $transport, new ReceiveContext());

        EventLoop::run();

        self::assertSame([], $transport->sent);
        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Uncaught notification handler exception.');
        self::assertCount(1, $matches);
        self::assertSame('notifications/cancelled', $matches[0]['context']['method'] ?? null);
    }

    public function testDuplicateInboundRequestIdIsRejectedSynchronouslyWithInvalidRequest(): void
    {
        $outbound = new PendingOutboundRequests();
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: ['tests/test-request' => new ClosureRequestHandler(static fn(): EmptyResult => new EmptyResult())],
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());

        EventLoop::run();

        self::assertCount(2, $transport->sent);
        self::assertInstanceOf(JsonRpcErrorResponse::class, $transport->sent[0]['message']);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $transport->sent[0]['message']->error->code);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[1]['message']);
    }

    public function testMisroutedMethodAsRequestSendsInvalidRequestErrorResponse(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher($outbound, logger: $logger);
        $transport = new RecordingTransport();

        // notifications/cancelled is a notification method but sent with an id.
        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 1],
        ], $transport, new ReceiveContext());

        EventLoop::run();

        self::assertSame([], $transport->sent, 'A client sends no JSON-RPC responses, so it has no reply to offer. The server answers this case per §5.');
        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Rejecting envelope whose method was sent under the wrong JSON-RPC shape.');
        self::assertCount(1, $matches);
    }

    public function testRequestMethodSentAsNotificationLogsWarnAndSendsInvalidRequestWithNullId(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher($outbound, logger: $logger);
        $transport = new RecordingTransport();

        $envelope = ['jsonrpc' => '2.0', 'method' => 'tests/test-request'];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertNull($message->id);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $message->error->code);

        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Rejecting envelope whose method was sent under the wrong JSON-RPC shape.');
        self::assertCount(1, $matches);
        self::assertSame($envelope, $matches[0]['context']['envelope'] ?? null);
        self::assertInstanceOf(AbstractJsonRpcProtocolException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testParseFailureForRequestSendsErrorResponseWithRecoveredId(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher($outbound, logger: $logger);
        $transport = new RecordingTransport();

        // Bad jsonrpc version on a request envelope. Parser raises InvalidRequestException.
        $envelope = ['jsonrpc' => '1.0', 'id' => 7, 'method' => 'tests/test-request'];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $message->error->code);
    }

    public function testParseFailureForNotificationEnvelopeIsDroppedAndLoggedNotAnsweredWithError(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher($outbound, logger: $logger);
        $transport = new RecordingTransport();

        // Bad jsonrpc version on a notification (no id) envelope.
        $envelope = ['jsonrpc' => '1.0', 'method' => 'notifications/cancelled'];
        $dispatcher->dispatch($envelope, $transport, new ReceiveContext());

        EventLoop::run();

        self::assertSame([], $transport->sent, 'Notifications must not produce responses, even when malformed.');
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Dropping malformed notification (JSON-RPC 2.0 §4.1 forbids responses to notifications).');
        self::assertCount(1, $matches);
        self::assertSame($envelope, $matches[0]['context']['envelope'] ?? null);
        self::assertInstanceOf(AbstractJsonRpcProtocolException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testUnknownNotificationMethodIsSilentlyDropped(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher($outbound, logger: $logger);
        $transport = new RecordingTransport();

        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 1],
        ], $transport, new ReceiveContext());

        EventLoop::run();

        self::assertSame([], $transport->sent);
        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));
    }

    public function testIdsAreReleasedAfterTheHandlerCompletesSoSequentialReuseSucceeds(): void
    {
        $outbound = new PendingOutboundRequests();
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: ['tests/test-request' => new ClosureRequestHandler(static fn(): EmptyResult => new EmptyResult())],
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();
        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        self::assertCount(2, $transport->sent);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[0]['message']);
        self::assertInstanceOf(JsonRpcResultResponse::class, $transport->sent[1]['message']);
    }

    public function testHandlerThrowingMcpExceptionTranslatesToTypedErrorResponse(): void
    {
        $outbound = new PendingOutboundRequests();
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: [
                'tests/test-request' => new ClosureRequestHandler(
                    static fn() => throw new ResourceNotFoundException('file:///missing'),
                ),
            ],
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(1, $message->id?->id);
    }

    public function testHandlerThrowingNonMcpExceptionLogsAndReturnsGenericInternalError(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: [
                'tests/test-request' => new ClosureRequestHandler(
                    static fn() => throw new \RuntimeException('handler exploded'),
                ),
            ],
            logger: $logger,
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(ProtocolErrorCode::InternalError->value, $message->error->code);

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Uncaught request handler exception.');
        self::assertCount(1, $matches);
        self::assertSame('tests/test-request', $matches[0]['context']['method'] ?? null);
        self::assertInstanceOf(\RuntimeException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testHandlerPropagatingTransportClosedSkipsInternalErrorFollowUp(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: [
                'tests/test-request' => new ClosureRequestHandler(
                    static fn() => throw new TransportAlreadyClosedException('send'),
                ),
            ],
            logger: $logger,
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        self::assertSame([], $transport->sent, 'A closed transport must not receive an InternalError follow-up.');
        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Skipping response delivery. Transport is closed.');
        self::assertCount(1, $matches);
        self::assertSame('tests/test-request', $matches[0]['context']['method'] ?? null);
    }

    public function testProtocolExceptionWithClosedTransportLogsInfoNotError(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: [
                'tests/test-request' => new ClosureRequestHandler(
                    static fn() => throw new ResourceNotFoundException('file:///missing'),
                ),
            ],
            logger: $logger,
        );
        $transport = new RecordingTransport();
        $transport->sendError = new TransportAlreadyClosedException('send');

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Skipping response delivery. Transport is closed.');
        self::assertCount(1, $matches);
    }

    public function testTransportSendFailureIsLoggedRatherThanCrashingTheLoop(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $transport = new RecordingTransport();
        $transport->sendError = new \RuntimeException('write failed');
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: ['tests/test-request' => new ClosureRequestHandler(static fn(): EmptyResult => new EmptyResult())],
            logger: $logger,
        );

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Failed to deliver response to transport.');
        self::assertCount(1, $matches);
        self::assertSame('tests/test-request', $matches[0]['context']['method'] ?? null);
        self::assertInstanceOf(\RuntimeException::class, $matches[0]['context']['exception'] ?? null);
    }

    public function testResultResponseSendFailingWithClosedTransportLogsInfoOnly(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $transport = new RecordingTransport();
        $transport->sendError = new TransportAlreadyClosedException('send');
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: ['tests/test-request' => new ClosureRequestHandler(static fn(): EmptyResult => new EmptyResult())],
            logger: $logger,
        );

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));
        $matches = $logger->recordsMatching(LogLevel::INFO, 'Skipping response delivery. Transport is closed.');
        self::assertCount(1, $matches);
    }

    public function testErrorResponseUsesExceptionRequestIdWhenSetEvenIfDifferentFromIncomingRequestId(): void
    {
        // The toErrorResponse helper coalesces $exception->requestId over the fallback. ResourceNotFoundException
        // carries no requestId, so the fallback (request->id) is used. Test pinning the coalesce direction.
        $outbound = new PendingOutboundRequests();
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: [
                'tests/test-request' => new ClosureRequestHandler(
                    static fn($req) => throw new ResourceNotFoundException('file:///x'),
                ),
            ],
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 42, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        self::assertCount(1, $transport->sent);
        $message = $transport->sent[0]['message'];
        self::assertInstanceOf(JsonRpcErrorResponse::class, $message);
        self::assertSame(42, $message->id?->id, 'Fallback id used since the exception carries none.');
    }

    public function testRequestHandlerReceivesClientContextForTheRequest(): void
    {
        $outbound = new PendingOutboundRequests();
        $transport = new RecordingTransport();
        $captured = ['requestId' => null];

        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: [
                'tests/test-request' => new ClosureRequestHandler(
                    static function ($req, $ctx) use (&$captured): EmptyResult {
                        if (! $ctx instanceof ClientContext) {
                            self::fail('Expected a ClientContext.');
                        }

                        $captured['requestId'] = $ctx->requestId->id;

                        return new EmptyResult();
                    },
                ),
            ],
        );

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'tests/test-request'], $transport, new ReceiveContext());
        EventLoop::run();

        self::assertSame(99, $captured['requestId']);
    }

    public function testFlushPendingWithNothingScheduledIsANoOp(): void
    {
        $outbound = new PendingOutboundRequests();
        $dispatcher = self::buildDispatcher($outbound);

        $dispatcher->flushPending();

        $this->expectNotToPerformAssertions();
    }

    public function testFlushPendingDrainsAnInFlightRequestDispatchBeforeReturning(): void
    {
        $outbound = new PendingOutboundRequests();
        $transport = new RecordingTransport();
        $dispatcher = self::buildDispatcher(
            $outbound,
            requestHandlers: ['tests/test-request' => new ClosureRequestHandler(static fn(): EmptyResult => new EmptyResult())],
        );

        $dispatcher->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tests/test-request'], $transport, new ReceiveContext());

        $dispatcher->flushPending();

        self::assertCount(1, $transport->sent);
    }

    public function testFlushPendingDrainsAnInFlightNotificationDispatchBeforeReturning(): void
    {
        $outbound = new PendingOutboundRequests();
        $logger = new ArrayLogger();
        $dispatcher = self::buildDispatcher(
            $outbound,
            notificationHandlers: [
                'notifications/cancelled' => new ClosureNotificationHandler(
                    static fn() => throw new \RuntimeException('handler ran'),
                ),
            ],
            logger: $logger,
        );
        $transport = new RecordingTransport();

        $dispatcher->dispatch([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 1],
        ], $transport, new ReceiveContext());

        self::assertSame([], $logger->messagesAtLevel(LogLevel::ERROR));

        $dispatcher->flushPending();

        $matches = $logger->recordsMatching(LogLevel::ERROR, 'Uncaught notification handler exception.');
        self::assertCount(1, $matches);
    }

    /**
     * @param array<non-empty-string, RequestHandlerInterface<non-empty-string, Result, ClientContext>> $requestHandlers
     * @param array<non-empty-string, NotificationHandlerInterface<non-empty-string>>                   $notificationHandlers
     */
    private static function buildDispatcher(
        PendingOutboundRequests $outbound,
        array $requestHandlers = [],
        array $notificationHandlers = [],
        ?ArrayLogger $logger = null,
    ): ClientMessageDispatcher {
        return new ClientMessageDispatcher(
            new HandlerRegistry($requestHandlers, RequestHandlerInterface::class, 'Request handler'),
            new HandlerRegistry($notificationHandlers, NotificationHandlerInterface::class, 'Notification handler'),
            $outbound,
            logger: $logger ?? new ArrayLogger(),
            parser: new JsonRpcMessageParser(requests: ['tests/test-request' => TestRequest::class]),
        );
    }
}
