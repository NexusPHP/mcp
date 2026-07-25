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

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\RequestHandler as AmpRequestHandler;
use Amp\Http\Server\Response as AmpResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface as PsrRequestHandler;

/**
 * Serves a PSR-15 handler from an `amphp/http-server` request handler.
 *
 * The SDK ships no HTTP server of its own: the Streamable HTTP transport is a
 * PSR-15 handler, and the host binds it to a socket. This adapter is that
 * binding for `amphp/http-server`. An `text/event-stream` response is piped
 * frame by frame instead of buffered, so notifications reach the client while
 * the call that emits them is still running.
 */
final readonly class PsrHttpAdapter implements AmpRequestHandler
{
    private const int CHUNK = 8192;

    public function __construct(
        private PsrRequestHandler $handler,
        private ServerRequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    #[Override]
    public function handleRequest(AmpRequest $request): AmpResponse
    {
        return self::adaptResponse($this->handler->handle($this->adaptRequest($request)));
    }

    private function adaptRequest(AmpRequest $request): ServerRequestInterface
    {
        $psrRequest = $this->requestFactory
            ->createServerRequest($request->getMethod(), $request->getUri())
            ->withProtocolVersion($request->getProtocolVersion())
            ->withBody($this->streamFactory->createStream($request->getBody()->buffer()))
        ;

        foreach ($request->getHeaders() as $name => $values) {
            $psrRequest = $psrRequest->withHeader($name, $values);
        }

        return $psrRequest;
    }

    private static function adaptResponse(ResponseInterface $response): AmpResponse
    {
        $body = $response->getBody();

        if (! self::isEventStream($response)) {
            return new AmpResponse($response->getStatusCode(), $response->getHeaders(), (string) $body);
        }

        $ampResponse = new AmpResponse(
            $response->getStatusCode(),
            $response->getHeaders(),
            new ReadableIterableStream(self::readFrames($body)),
        );

        // Retires the transport's stream when the client disconnects, so a
        // dropped connection does not leave frames pushed into a dead socket.
        $ampResponse->onDispose($body->close(...));

        return $ampResponse;
    }

    /**
     * @return Generator<int, string>
     */
    private static function readFrames(StreamInterface $body): Generator
    {
        $frame = $body->read(self::CHUNK);

        while ('' !== $frame) {
            yield $frame;

            $frame = $body->read(self::CHUNK);
        }
    }

    private static function isEventStream(ResponseInterface $response): bool
    {
        return str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream');
    }
}
