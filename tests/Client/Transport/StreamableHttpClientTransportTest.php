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

namespace Nexus\Mcp\Tests\Client\Transport;

use Amp\DeferredFuture;
use Amp\Http\Client\HttpException;
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Client\Transport\StreamableHttpClientTransport;
use Nexus\Mcp\Core\Exception\OutboundRequestFailedException;
use Nexus\Mcp\Core\Exception\ResponseTooLargeException;
use Nexus\Mcp\Core\Exception\TransportAlreadyClosedException;
use Nexus\Mcp\Core\Exception\TransportAlreadyStartedException;
use Nexus\Mcp\Core\Exception\TransportNotStartedException;
use Nexus\Mcp\Core\Exception\UnexpectedHttpStatusException;
use Nexus\Mcp\Core\Http\HeaderValueCodec;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Error\InternalError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcMessage;
use Nexus\Mcp\Core\Schema\MetaObject\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Notification\ToolListChangedNotification;
use Nexus\Mcp\Core\Schema\NotificationParams\EmptyNotificationParams;
use Nexus\Mcp\Core\Schema\ProtocolVersion;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Request\DiscoverRequest;
use Nexus\Mcp\Core\Schema\Request\ReadResourceRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\CallToolRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\EmptyRequestParams;
use Nexus\Mcp\Core\Schema\RequestParams\ReadResourceRequestParams;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Client\Http\RecordingHttpClient;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\EnvelopeLog;
use Nexus\Mcp\Tests\Fixtures\Core\Transport\FaultLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

use function Amp\ByteStream\buffer;
use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(StreamableHttpClientTransport::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class StreamableHttpClientTransportTest extends AbstractMcpTestCase
{
    public function testPostsTheEnvelopeWithTheRequiredHeaders(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::resultEnvelope());
        $transport = self::makeTransport($http);

        self::exchange($transport, self::discoverRequest());

        self::assertCount(1, $http->requests);
        $request = $http->readRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://mcp.test/mcp', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeader('Content-Type'));
        self::assertSame('application/json, text/event-stream', $request->getHeader('Accept'));
        self::assertSame(ProtocolVersion::LATEST_VERSION, $request->getHeader('MCP-Protocol-Version'));
        self::assertSame('server/discover', $request->getHeader('Mcp-Method'));
        self::assertSame(self::discoverRequest()->toArray(), $http->readSentEnvelope());
    }

    public function testMirrorsTheToolNameIntoTheNameHeader(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::resultEnvelope());
        $transport = self::makeTransport($http);

        self::exchange($transport, new CallToolRequest(
            id: new RequestId(id: 1),
            params: new CallToolRequestParams(name: 'get_weather', meta: RequestMetaObjectFactory::create()),
        ));

        self::assertSame('tools/call', $http->readRequest()->getHeader('Mcp-Method'));
        self::assertSame('get_weather', $http->readRequest()->getHeader('Mcp-Name'));
    }

    public function testTheHeadersOnTheSendContextAreCarriedByThePost(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::resultEnvelope());
        $transport = self::makeTransport($http);

        self::exchange($transport, self::discoverRequest(), new SendContext(headers: ['Mcp-Param-Region' => 'us-west1']));

        self::assertSame('us-west1', $http->readRequest()->getHeader('Mcp-Param-Region'));
    }

    public function testEmitsABufferedJsonResponse(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::resultEnvelope());
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([self::resultEnvelope()], $received->envelopes);
    }

    public function testEmitsAnErrorResponseSoThePendingRequestCanReject(): void
    {
        $envelope = ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32_020, 'message' => 'Header mismatch']];
        $http = (new RecordingHttpClient())->willAnswerJson($envelope, status: 400);
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([$envelope], $received->envelopes);
        self::assertSame([], $faults->messages, 'An emitted error envelope ends the exchange, faultlessly.');
    }

    public function testEmitsEveryFrameOfAnSseResponse(): void
    {
        $progress = ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progressToken' => 'p-1', 'progress' => 0.5]];
        $http = (new RecordingHttpClient())->willAnswerStream([
            self::frame($progress),
            self::frame(self::resultEnvelope()),
        ]);
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([$progress, self::resultEnvelope()], $received->envelopes);
        self::assertSame([], $faults->messages, 'A consumed stream must not also be buffered as a body.');
    }

    public function testAssemblesAnSseFrameSplitAcrossChunks(): void
    {
        $frame = self::frame(self::resultEnvelope());
        $http = (new RecordingHttpClient())->willAnswerStream([
            substr($frame, 0, 12),
            substr($frame, 12),
        ]);
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([self::resultEnvelope()], $received->envelopes);
    }

    public function testIgnoresAKeepAliveComment(): void
    {
        $http = (new RecordingHttpClient())->willAnswerStream([": keep-alive\n\n", self::frame(self::resultEnvelope())]);
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([self::resultEnvelope()], $received->envelopes);
    }

    public function testAResourceUriRidesTheNameHeaderVerbatim(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::resultEnvelope());
        $transport = self::makeTransport($http);
        $uri = 'file:///tmp/notes.txt';

        self::exchange($transport, new ReadResourceRequest(
            id: new RequestId(id: 1),
            params: new ReadResourceRequestParams(uri: $uri, meta: RequestMetaObjectFactory::create()),
        ));

        $header = $http->readRequest()->getHeader('Mcp-Name');
        self::assertSame($uri, $header);
        self::assertSame($uri, HeaderValueCodec::decode($header), 'The server must be able to read it back as the body value.');
    }

    public function testDropsAnOutboundResponseTheSpecForbidsAClientFromSending(): void
    {
        $logger = new ArrayLogger();
        $http = new RecordingHttpClient();
        $transport = self::makeTransport($http, logger: $logger);

        self::exchange($transport, new JsonRpcErrorResponse(id: new RequestId(id: 1), error: new InternalError(message: 'boom')));

        self::assertSame([], $http->requests, 'A response must never reach the endpoint.');
        $matches = $logger->recordsMatching(LogLevel::WARNING, '{label} transport dropped an outbound response, which a client must not send.');
        self::assertCount(1, $matches);
        self::assertSame(['label' => 'Streamable HTTP client'], $matches[0]['context']);
    }

    public function testKeepsSlashesAndUnicodeUnescapedInTheBody(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::resultEnvelope());
        $transport = self::makeTransport($http);
        $path = 'file:///tmp/世界.txt';

        self::exchange($transport, new CallToolRequest(
            id: new RequestId(id: 1),
            params: new CallToolRequestParams(name: 'read_file', arguments: ['path' => $path], meta: RequestMetaObjectFactory::create()),
        ));

        $body = buffer($http->readRequest()->getBody()->getContent());
        self::assertStringContainsString($path, $body);
        self::assertStringNotContainsString('file:\\/\\/', $body, 'Slashes stay unescaped.');
        self::assertStringNotContainsString('\\u4e16', $body, 'Non-ASCII stays unescaped.');
    }

    public function testAnEmptyObjectSlotIsPostedAsAnObjectNotAnArray(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::resultEnvelope());
        $transport = self::makeTransport($http);

        self::exchange($transport, new DiscoverRequest(
            id: new RequestId(id: 1),
            params: new EmptyRequestParams(meta: new RequestMetaObject(
                protocolVersion: new ProtocolVersion(version: ProtocolVersion::LATEST_VERSION),
                clientCapabilities: new ClientCapabilities(),
            )),
        ));

        self::assertStringContainsString(
            \sprintf('"%s":{}', RequestMetaObject::CLIENT_CAPABILITIES_KEY),
            buffer($http->readRequest()->getBody()->getContent()),
        );
    }

    public function testDetectsAnUppercaseContentType(): void
    {
        // RFC 9110 makes the media type case-insensitive, so a shouting server still gets parsed as a stream.
        $http = (new RecordingHttpClient())->willAnswerWithContentType('TEXT/EVENT-STREAM', [self::frame(self::resultEnvelope())]);
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([self::resultEnvelope()], $received->envelopes);
    }

    public function testReadsAJsonBodyWhoseContentTypeParameterNamesAStream(): void
    {
        $http = (new RecordingHttpClient())->willAnswerWithContentType(
            'application/json; note="text/event-stream"',
            [json_encode(self::resultEnvelope(), \JSON_THROW_ON_ERROR)],
        );
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([self::resultEnvelope()], $received->envelopes, 'A buffered body must be read as one.');
    }

    #[DataProvider('provideKeepsReadingAfterAMalformedFrameCases')]
    public function testKeepsReadingAfterAMalformedFrame(string $payload): void
    {
        $http = (new RecordingHttpClient())->willAnswerStream([
            \sprintf("event: message\ndata: %s\n\n", $payload).self::frame(self::resultEnvelope()),
        ]);
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([self::resultEnvelope()], $received->envelopes, 'The frame after the bad one still arrives.');
        self::assertCount(1, $faults->messages);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideKeepsReadingAfterAMalformedFrameCases(): iterable
    {
        yield 'undecodable json' => ['{not json'];

        yield 'json that is not an envelope' => ['[1, 2]'];
    }

    public function testAListenerFaultOnAFrameIsNotMistakenForAnUnreadableOne(): void
    {
        $http = (new RecordingHttpClient())->willAnswerStream([self::frame(self::resultEnvelope())]);
        $transport = self::makeTransport($http);
        $faults = self::captureFaults($transport);
        $transport->onMessage(static function (): void {
            throw new \InvalidArgumentException('the protocol layer rejected this envelope');
        });

        self::exchange($transport, self::discoverRequest());

        $fault = $faults->readFault();

        if (! $fault instanceof OutboundRequestFailedException) {
            self::fail('A listener fault must fail the request it carried rather than read on as if the frame were unreadable.');
        }

        self::assertSame('the protocol layer rejected this envelope', $fault->getPrevious()?->getMessage());
    }

    public function testCloseCancelsAnOpenStreamWithoutReportingAFault(): void
    {
        $http = (new RecordingHttpClient())->willAnswerOpenStream([self::frame(self::resultEnvelope())]);
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        $transport->send(self::discoverRequest());
        delay(0.05);
        self::assertSame([self::resultEnvelope()], $received->envelopes, 'The frames already sent arrive.');

        $transport->close();

        self::assertSame([], $faults->messages, 'Cancelling at shutdown is not a transport fault.');
    }

    public function testAbortStopsOneStreamAndLeavesTheOthersRunning(): void
    {
        $later = ['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed', 'params' => []];
        $resume = new DeferredFuture();
        $http = (new RecordingHttpClient())
            ->willAnswerOpenStream([self::frame(self::resultEnvelope())])
            ->willAnswerOpenStream([self::frame(self::resultEnvelope())], $resume->getFuture(), [self::frame($later)])
        ;
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        $transport->send(self::discoverRequest(id: 1));
        $transport->send(self::discoverRequest(id: 2));
        delay(0.05);

        $transport->abort(new RequestId(id: 1));
        $resume->complete();
        delay(0.05);

        self::assertSame(
            [self::resultEnvelope(), self::resultEnvelope(), $later],
            $received->envelopes,
            'Aborting one exchange must not stop the others.',
        );
        self::assertSame([], $faults->messages, 'A caller abandoning its own request is not a fault.');

        $transport->close();
    }

    public function testAbortingAnUnknownRequestDoesNothing(): void
    {
        $later = ['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed', 'params' => []];
        $resume = new DeferredFuture();
        $http = (new RecordingHttpClient())->willAnswerOpenStream(
            [self::frame(self::resultEnvelope())],
            $resume->getFuture(),
            [self::frame($later)],
        );
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);

        $transport->send(self::discoverRequest(id: 1));
        delay(0.05);

        $transport->abort(new RequestId(id: 'never-sent'));
        $resume->complete();
        delay(0.05);

        self::assertSame([self::resultEnvelope(), $later], $received->envelopes);

        $transport->close();
    }

    public function testAnIntIdAndItsStringSpellingNameDifferentExchanges(): void
    {
        $later = ['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed', 'params' => []];
        $resume = new DeferredFuture();
        $http = (new RecordingHttpClient())
            ->willAnswerOpenStream([self::frame(self::resultEnvelope())], $resume->getFuture(), [self::frame($later)])
            ->willAnswerOpenStream([self::frame(self::resultEnvelope())])
        ;
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);

        $transport->send(self::discoverRequest(id: 1));
        $transport->send(self::discoverRequest(id: '1'));
        delay(0.05);

        $transport->abort(new RequestId(id: '1'));
        $resume->complete();
        delay(0.05);

        self::assertSame(
            [self::resultEnvelope(), self::resultEnvelope(), $later],
            $received->envelopes,
            'Aborting the string id must leave the int one reading.',
        );

        $transport->close();
    }

    public function testANotificationExchangeIsNeverTracked(): void
    {
        $later = ['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed', 'params' => []];
        $resume = new DeferredFuture();
        $http = (new RecordingHttpClient())
            ->willAcceptNotification()
            ->willAnswerOpenStream([self::frame(self::resultEnvelope())], $resume->getFuture(), [self::frame($later)])
        ;
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);

        $transport->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));
        $transport->send(self::discoverRequest(id: 1));
        delay(0.05);

        $resume->complete();
        delay(0.05);

        self::assertSame([self::resultEnvelope(), $later], $received->envelopes);

        $transport->close();
    }

    public function testAbortStopsTheStreamFromBeingReadAnyFurther(): void
    {
        $later = ['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed', 'params' => []];
        $resume = new DeferredFuture();
        $http = (new RecordingHttpClient())->willAnswerOpenStream(
            [self::frame(self::resultEnvelope())],
            $resume->getFuture(),
            [self::frame($later)],
        );
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);

        $transport->send(self::discoverRequest(id: 1));
        delay(0.05);
        self::assertSame([self::resultEnvelope()], $received->envelopes);

        $transport->abort(new RequestId(id: 1));
        $resume->complete();
        delay(0.05);

        self::assertSame([self::resultEnvelope()], $received->envelopes, 'An aborted exchange stops reading its stream.');

        $transport->close();
    }

    public function testAbortIsIdempotent(): void
    {
        $later = ['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed', 'params' => []];
        $resume = new DeferredFuture();
        $http = (new RecordingHttpClient())
            ->willAnswerOpenStream([self::frame(self::resultEnvelope())])
            ->willAnswerOpenStream([self::frame(self::resultEnvelope())], $resume->getFuture(), [self::frame($later)])
        ;
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        $transport->send(self::discoverRequest(id: 1));
        $transport->send(self::discoverRequest(id: 2));
        delay(0.05);

        $transport->abort(new RequestId(id: 1));
        $transport->abort(new RequestId(id: 1));
        $resume->complete();
        delay(0.05);

        self::assertSame([self::resultEnvelope(), self::resultEnvelope(), $later], $received->envelopes);
        self::assertSame([], $faults->messages);

        $transport->close();
    }

    public function testAThrowingErrorListenerDoesNotWedgeTheTransport(): void
    {
        $http = (new RecordingHttpClient())
            ->willFail(new HttpException('connection reset'))
            ->willAnswerJson(self::resultEnvelope())
        ;
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $transport->onError(static function (): void {
            throw new \RuntimeException('listener blew up');
        });

        $transport->send(self::discoverRequest(id: 1));
        delay(0.05);

        self::exchange($transport, self::discoverRequest(id: 2));

        self::assertSame([self::resultEnvelope()], $received->envelopes);
    }

    public function testCloseSignalsCloseEvenWhenADrainListenerThrows(): void
    {
        $transport = self::makeTransport(new RecordingHttpClient());
        $closed = false;
        $transport->onDrain(static function (): void {
            throw new \RuntimeException('drain boom');
        });
        $transport->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        try {
            $transport->close();
        } catch (\RuntimeException) {
        }

        self::assertTrue($closed);
    }

    public function testEmitsNothingForAnAcceptedNotification(): void
    {
        $http = (new RecordingHttpClient())->willAcceptNotification();
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        self::exchange($transport, new ToolListChangedNotification(params: new EmptyNotificationParams()));

        self::assertSame([], $received->envelopes);
        self::assertSame([], $faults->messages, 'A bodiless 202 is not a fault.');
        self::assertCount(1, $http->requests, 'The notification is still POSTed.');
    }

    public function testReportsATransportFailureThroughOnError(): void
    {
        $http = (new RecordingHttpClient())->willFail(new HttpException('connection refused'));
        $transport = self::makeTransport($http);
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame(
            ['The exchange carrying request 1 failed before a response arrived.'],
            $faults->messages,
        );

        $fault = $faults->readFault();

        if (! $fault instanceof OutboundRequestFailedException) {
            self::fail('Expected the failure to name the request it was carrying.');
        }

        self::assertSame(1, $fault->requestId->id, 'The caller awaiting this id is the one to fail.');
        self::assertInstanceOf(HttpException::class, $fault->getPrevious(), 'The underlying fault stays reachable.');
    }

    public function testReportsANotificationFailureUncorrelated(): void
    {
        $http = (new RecordingHttpClient())->willFail(new HttpException('connection refused'));
        $transport = self::makeTransport($http);
        $faults = self::captureFaults($transport);

        self::exchange($transport, new ToolListChangedNotification(params: new EmptyNotificationParams()));

        self::assertSame(['connection refused'], $faults->messages);
        self::assertInstanceOf(HttpException::class, $faults->readFault());
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[DataProvider('provideReportsAnUndecodablePayloadThroughOnErrorCases')]
    public function testReportsAnUndecodablePayloadThroughOnError(array|string $body): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson($body);
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([], $received->envelopes, 'The protocol layer must not see a payload it cannot parse.');
        self::assertCount(1, $faults->messages);
        self::assertInstanceOf(
            OutboundRequestFailedException::class,
            $faults->readFault(),
            'A buffered body that cannot be read is the whole answer, so its caller can never be served.',
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function provideReportsAnUndecodablePayloadThroughOnErrorCases(): iterable
    {
        yield 'not json' => ['{"jsonrpc":'];

        yield 'json that is not an object' => ['[1, 2]'];

        yield 'json scalar' => ['42'];
    }

    public function testAnUnreadableStreamFrameDoesNotEndTheExchange(): void
    {
        $http = (new RecordingHttpClient())->willAnswerStream([
            "data: {\"jsonrpc\":\n\n",
            'data: '.json_encode(self::resultEnvelope(), \JSON_THROW_ON_ERROR)."\n\n",
        ]);
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([self::resultEnvelope()], $received->envelopes, 'The frame after the bad one still arrives.');
        self::assertCount(1, $faults->messages);
        self::assertNotInstanceOf(
            OutboundRequestFailedException::class,
            $faults->readFault(),
            'The exchange read on, so the caller is not failed.',
        );
    }

    public function testSendBeforeStartThrows(): void
    {
        $this->expectException(TransportNotStartedException::class);

        self::makeTransport(new RecordingHttpClient(), start: false)->send(self::discoverRequest());
    }

    public function testSendAfterCloseThrows(): void
    {
        $transport = self::makeTransport(new RecordingHttpClient());
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->send(self::discoverRequest());
    }

    public function testStartTwiceThrows(): void
    {
        $transport = self::makeTransport(new RecordingHttpClient());

        $this->expectException(TransportAlreadyStartedException::class);

        $transport->start();
    }

    public function testStartAfterCloseThrows(): void
    {
        $transport = self::makeTransport(new RecordingHttpClient());
        $transport->close();

        $this->expectException(TransportAlreadyClosedException::class);

        $transport->start();
    }

    public function testCloseDrainsThenSignalsCloseAndIsIdempotent(): void
    {
        $transport = self::makeTransport(new RecordingHttpClient());
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
        $transport = self::makeTransport(new RecordingHttpClient());
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

    public function testCloseBeforeStartStillSignalsClose(): void
    {
        $transport = self::makeTransport(new RecordingHttpClient(), start: false);
        $closed = false;
        $transport->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        $transport->close();

        self::assertTrue($closed, 'A transport that never started holds no lifetime to cancel.');
    }

    public function testCloseAwaitsAnInFlightExchange(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::resultEnvelope());
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);

        $transport->send(self::discoverRequest());
        $transport->close();

        self::assertSame([self::resultEnvelope()], $received->envelopes, 'A response already on the way must still land.');
    }

    public function testAnErrorStatusFailsTheRequestItsExchangeCarries(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['error' => 'insufficient_scope'], 403);
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([], $received->envelopes);
        $fault = $faults->readFault();

        if (! $fault instanceof OutboundRequestFailedException) {
            self::fail('An error status must still fail the request it was carrying.');
        }

        $cause = $fault->getPrevious();

        if (! $cause instanceof UnexpectedHttpStatusException) {
            self::fail('The failure must name the unexpected status.');
        }

        self::assertSame(403, $cause->status);
        self::assertSame('The endpoint answered 403 where 200 or 202 was expected.', $cause->getMessage());
        self::assertSame('{"error":"insufficient_scope"}', $cause->body);
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[DataProvider('provideAnUncorrelatedBodyOnAnErrorStatusFailsTheRequestCases')]
    public function testAnUncorrelatedBodyOnAnErrorStatusFailsTheRequest(array|string $body): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson($body, 400);
        $transport = self::makeTransport($http);
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([], $received->envelopes, 'An envelope that cannot settle this request must not be emitted.');
        $fault = $faults->readFault();

        if (! $fault instanceof OutboundRequestFailedException) {
            self::fail('An uncorrelatable answer must fail the request its exchange carries.');
        }

        self::assertInstanceOf(UnexpectedHttpStatusException::class, $fault->getPrevious());
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function provideAnUncorrelatedBodyOnAnErrorStatusFailsTheRequestCases(): iterable
    {
        yield 'an id-less error envelope' => [['jsonrpc' => '2.0', 'error' => ['code' => -32_600, 'message' => 'Invalid Request']]];

        yield 'an error envelope answering some other id' => [['jsonrpc' => '2.0', 'id' => 99, 'error' => ['code' => -32_600, 'message' => 'Invalid Request']]];

        yield 'a notification-shaped body' => [['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => []]];

        yield 'an envelope without a result or error member' => [['jsonrpc' => '2.0', 'id' => 1]];

        yield 'a JSON scalar' => ['"nope"'];
    }

    public function testAnOversizedErrorBodyStillReportsTheStatus(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(str_repeat('a', 512), 502);
        $transport = new StreamableHttpClientTransport('https://mcp.test/mcp', $http, maxResponseBytes: 64);
        $transport->start();
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        $fault = $faults->readFault();

        if (! $fault instanceof OutboundRequestFailedException) {
            self::fail('An oversized error body must still fail the request it was carrying.');
        }

        $cause = $fault->getPrevious();

        if (! $cause instanceof UnexpectedHttpStatusException) {
            self::fail('The failure must name the unexpected status, not the size cap.');
        }

        self::assertSame(502, $cause->status);
        self::assertNull($cause->body);
    }

    public function testAnSseErrorStatusFailsWithoutReadingTheStream(): void
    {
        $http = (new RecordingHttpClient())->willAnswerStream([": keep-alive\n\n"], 503);
        $transport = self::makeTransport($http);
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        $fault = $faults->readFault();

        if (! $fault instanceof OutboundRequestFailedException) {
            self::fail('An error status on a stream must fail the request without consuming the stream.');
        }

        $cause = $fault->getPrevious();

        if (! $cause instanceof UnexpectedHttpStatusException) {
            self::fail('The failure must name the unexpected status.');
        }

        self::assertSame(503, $cause->status);
        self::assertNull($cause->body, 'A stream that was never read leaves no body to report.');
    }

    public function testA202AnsweringARequestFailsIt(): void
    {
        $http = (new RecordingHttpClient())->willAcceptNotification();
        $transport = self::makeTransport($http);
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        $fault = $faults->readFault();

        if (! $fault instanceof OutboundRequestFailedException) {
            self::fail('A request answered with a bodiless acknowledgement must fail rather than dangle.');
        }

        $cause = $fault->getPrevious();

        if (! $cause instanceof UnexpectedHttpStatusException) {
            self::fail('The failure must name the unexpected status.');
        }

        self::assertSame(202, $cause->status);
    }

    public function testAnErrorStatusOnANotificationSurfacesTheStatus(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(['error' => 'nope'], 403);
        $transport = self::makeTransport($http);
        $faults = self::captureFaults($transport);

        self::exchange($transport, new ToolListChangedNotification(params: new EmptyNotificationParams()));

        $fault = $faults->readFault();

        if (! $fault instanceof UnexpectedHttpStatusException) {
            self::fail('A refused notification has no request to fail, so the status surfaces raw.');
        }

        self::assertSame(403, $fault->status);
    }

    public function testAbandonsABufferedBodyThatOutgrowsTheResponseCap(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(str_repeat('a', 512));
        $transport = new StreamableHttpClientTransport('https://mcp.test/mcp', $http, maxResponseBytes: 64);
        $transport->start();
        $received = self::captureMessages($transport);
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        self::assertSame([], $received->envelopes);
        $fault = $faults->readFault();

        if (! $fault instanceof OutboundRequestFailedException) {
            self::fail('An abandoned response must still fail the request it was carrying.');
        }

        self::assertInstanceOf(ResponseTooLargeException::class, $fault->getPrevious());
    }

    public function testAbandonsAStreamFrameThatOutgrowsTheResponseCap(): void
    {
        $http = (new RecordingHttpClient())->willAnswerStream(['data: '.str_repeat('a', 512)]);
        $transport = new StreamableHttpClientTransport('https://mcp.test/mcp', $http, maxResponseBytes: 64);
        $transport->start();
        $faults = self::captureFaults($transport);

        self::exchange($transport, self::discoverRequest());

        $fault = $faults->readFault();

        if (! $fault instanceof OutboundRequestFailedException) {
            self::fail('An abandoned stream must still fail the request it was carrying.');
        }

        self::assertInstanceOf(ResponseTooLargeException::class, $fault->getPrevious());
    }

    public function testDrainRunsBeforeTheTransportIsMarkedClosed(): void
    {
        $transport = self::makeTransport((new RecordingHttpClient())->willAcceptNotification());
        $sendFaults = new FaultLog();

        $transport->onDrain(static function () use ($transport, $sendFaults): void {
            try {
                $transport->send(new ToolListChangedNotification(params: new EmptyNotificationParams()));
            } catch (\Throwable $e) {
                $sendFaults->record($e);
            }
        });

        $transport->close();

        self::assertSame(
            [],
            $sendFaults->messages,
            'Draining exists so a listener can settle an exchange, which it cannot do once sends are refused.',
        );
    }

    public function testStartLogsTheEndpoint(): void
    {
        $logger = new ArrayLogger();
        self::makeTransport(new RecordingHttpClient(), logger: $logger);

        $matches = $logger->recordsMatching(LogLevel::INFO, '{label} transport started. Endpoint: {endpoint}.');
        self::assertCount(1, $matches);
        self::assertSame(
            ['label' => 'Streamable HTTP client', 'endpoint' => 'https://mcp.test/mcp'],
            $matches[0]['context'],
        );
    }

    public function testRejectsAnEmptyEndpoint(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('Streamable HTTP client endpoint must be a non-empty string.');

        // @phpstan-ignore argument.type
        new StreamableHttpClientTransport('', new RecordingHttpClient());
    }

    #[DataProvider('provideRejectsANonPositiveReadTimeoutCases')]
    public function testRejectsANonPositiveReadTimeout(float $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Streamable HTTP client read timeout must be positive, /');

        new StreamableHttpClientTransport('https://mcp.test/mcp', new RecordingHttpClient(), readTimeout: $timeout);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideRejectsANonPositiveReadTimeoutCases(): iterable
    {
        yield 'zero' => [0.0];

        yield 'negative' => [-1.0];
    }

    #[DataProvider('provideRejectsANonPositiveMaxResponseSizeCases')]
    public function testRejectsANonPositiveMaxResponseSize(int $maxResponseBytes, string $expected): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expected);

        new StreamableHttpClientTransport('https://mcp.test/mcp', new RecordingHttpClient(), maxResponseBytes: $maxResponseBytes);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideRejectsANonPositiveMaxResponseSizeCases(): iterable
    {
        yield 'zero' => [0, 'Streamable HTTP client maximum response size must be a positive integer, 0 given.'];

        yield 'negative' => [-1, 'Streamable HTTP client maximum response size must be a positive integer, -1 given.'];
    }

    public function testDisablesTheTransferTimeoutAndAppliesTheReadTimeout(): void
    {
        $http = (new RecordingHttpClient())->willAnswerJson(self::resultEnvelope());
        $transport = new StreamableHttpClientTransport('https://mcp.test/mcp', $http, readTimeout: 45.0);
        $transport->start();

        self::exchange($transport, self::discoverRequest());

        self::assertSame(0.0, $http->readRequest()->getTransferTimeout());
        self::assertSame(45.0, $http->readRequest()->getInactivityTimeout());
    }

    /**
     * Drives one complete round trip, turning the loop for the detached POST since `close()` would cancel the exchange instead.
     */
    private static function exchange(StreamableHttpClientTransport $transport, JsonRpcMessage $message, ?SendContext $context = null): void
    {
        $transport->send($message, $context);
        delay(0.05);
    }

    private static function makeTransport(
        RecordingHttpClient $http,
        bool $start = true,
        ?ArrayLogger $logger = null,
    ): StreamableHttpClientTransport {
        $transport = new StreamableHttpClientTransport('https://mcp.test/mcp', $http, $logger ?? new ArrayLogger());

        if ($start) {
            $transport->start();
        }

        return $transport;
    }

    private static function captureFaults(StreamableHttpClientTransport $transport): FaultLog
    {
        $log = new FaultLog();
        $transport->onError(static function (\Throwable $fault) use ($log): void {
            $log->record($fault);
        });

        return $log;
    }

    private static function captureMessages(StreamableHttpClientTransport $transport): EnvelopeLog
    {
        $log = new EnvelopeLog();
        $transport->onMessage(static function (array $envelope) use ($log): void {
            $log->record($envelope);
        });

        return $log;
    }

    /**
     * @param int|non-empty-string $id
     */
    private static function discoverRequest(int|string $id = 1): DiscoverRequest
    {
        return new DiscoverRequest(id: new RequestId(id: $id), params: new EmptyRequestParams(meta: RequestMetaObjectFactory::create()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function resultEnvelope(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'complete']];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function frame(array $envelope): string
    {
        return \sprintf("event: message\ndata: %s\n\n", json_encode($envelope, \JSON_THROW_ON_ERROR));
    }
}
