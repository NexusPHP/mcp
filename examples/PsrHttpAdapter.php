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

final readonly class PsrHttpAdapter implements AmpRequestHandler
{
    private const int CHUNK = 8_192;

    public function __construct(
        private PsrRequestHandler $handler,
        private ServerRequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    #[Override]
    public function handleRequest(AmpRequest $request): AmpResponse
    {
        return $this->adaptResponse($this->handler->handle($this->adaptRequest($request)));
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

    private function adaptResponse(ResponseInterface $response): AmpResponse
    {
        $body = $response->getBody();

        if (! $this->isEventStream($response)) {
            return new AmpResponse($response->getStatusCode(), $this->readHeaders($response), (string) $body);
        }

        $ampResponse = new AmpResponse(
            $response->getStatusCode(),
            $this->readHeaders($response),
            new ReadableIterableStream($this->readFrames($body)),
        );

        $ampResponse->onDispose($body->close(...));

        return $ampResponse;
    }

    /**
     * @return array<non-empty-string, list<string>>
     */
    private function readHeaders(ResponseInterface $response): array
    {
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            if ('' !== $name) {
                $headers[$name] = $values;
            }
        }

        return $headers;
    }

    /**
     * @return Generator<int, string>
     */
    private function readFrames(StreamInterface $body): Generator
    {
        $frame = $body->read(self::CHUNK);

        while ('' !== $frame) {
            yield $frame;

            $frame = $body->read(self::CHUNK);
        }
    }

    private function isEventStream(ResponseInterface $response): bool
    {
        return str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream');
    }
}
