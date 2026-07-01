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

use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LogLevel;

use function Amp\async;

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

    public function testValidNotificationIsEmittedAndAcceptedWith202(): void
    {
        $transport = self::makeTransport();
        $transport->start();

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
        $transport->start();

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
        $transport = self::makeTransport();
        self::listen($transport);

        $response = self::handle($transport, self::makePost([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = self::decode($response);
        self::assertSame('2.0', $body['jsonrpc'] ?? null);
        self::assertSame(7, $body['id'] ?? null);
        self::assertArrayHasKey('result', $body);
    }

    public function testStringClientIdIsRestoredOnTheResponse(): void
    {
        $transport = self::makeTransport();
        self::listen($transport);

        $response = self::handle($transport, self::makePost([
            'jsonrpc' => '2.0',
            'id' => 'req-abc',
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ]));

        self::assertSame('req-abc', self::decode($response)['id'] ?? null);
    }

    public function testResponseBodyLeavesSlashesAndUnicodeUnescaped(): void
    {
        $transport = self::makeTransport();
        self::listen($transport);

        $response = self::handle($transport, self::makePost([
            'jsonrpc' => '2.0',
            'id' => 'scope/日本',
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ]));

        $raw = (string) $response->getBody();
        self::assertStringContainsString('scope/日本', $raw);
        self::assertStringNotContainsString('scope\/', $raw);
    }

    public function testConcurrentRequestsSharingAClientIdDoNotCollide(): void
    {
        $transport = self::makeTransport();
        self::listen($transport);

        $post = self::makePost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ]);

        // Both coroutines start on `async()`, so awaiting them one after the other still runs them concurrently.
        $firstPending = async(static fn(): ResponseInterface => $transport->handle($post));
        $secondPending = async(static fn(): ResponseInterface => $transport->handle(self::makePost([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => RequestMetaObjectFactory::shape()],
        ])));

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

    public function testSendBeforeStartThrows(): void
    {
        $this->expectException(TransportNotStartedException::class);

        self::makeTransport()->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));
    }

    public function testSendAfterCloseThrows(): void
    {
        $transport = self::makeTransport();
        $transport->start();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));
    }

    public function testSendDropsNotification(): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger);
        $transport->start();

        $transport->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));

        self::assertCount(1, $logger->recordsMatching(LogLevel::DEBUG, 'Dropping a notification: the JSON response path cannot stream it.'));
        self::assertCount(0, $logger->recordsMatching(LogLevel::WARNING, 'Dropping an unexpected server-initiated request.'));
    }

    public function testSendDropsServerInitiatedRequest(): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger);
        $transport->start();

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
        $transport->start();

        $transport->send(new JsonRpcErrorResponse(id: null, error: new InternalError(message: 'boom')));

        self::assertCount(1, $logger->recordsMatching(LogLevel::WARNING, 'Discarding a response that carries no id to correlate.'));
        self::assertCount(0, $logger->recordsMatching(LogLevel::WARNING, 'Dropping an unexpected server-initiated request.'));
    }

    #[DataProvider('provideSendDropsOrphanResponseCases')]
    public function testSendDropsOrphanResponse(int|string $id): void
    {
        $logger = new ArrayLogger();
        $transport = self::makeTransport($logger);
        $transport->start();

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
        $transport = self::makeTransport();
        $transport->start();

        $this->expectException(TransportAlreadyStartedException::class);

        $transport->start();
    }

    public function testStartAfterCloseThrows(): void
    {
        $transport = self::makeTransport();
        $transport->start();
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->start();
    }

    public function testCloseDrainsBeforeSignallingCloseAndIsIdempotent(): void
    {
        $transport = self::makeTransport();
        $transport->start();

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
        $transport->start();

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

    private static function makeTransport(?ArrayLogger $logger = null): StreamableHttpServerTransport
    {
        $factory = new Psr17Factory();

        return new StreamableHttpServerTransport($factory, $factory, $logger ?? new ArrayLogger());
    }

    private static function listen(StreamableHttpServerTransport $transport): void
    {
        new ServerBuilder()->setServerInfo('demo', '1.0.0')->build()->listen($transport);
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private static function makePost(array|string $body): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $raw = \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR);

        return $factory->createServerRequest('POST', 'https://mcp.test/')->withBody($factory->createStream($raw));
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
