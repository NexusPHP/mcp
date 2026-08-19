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

namespace Nexus\Mcp\Tests\Server\Transport\Http\Middleware;

use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Server\Transport\Http\Middleware\RequestBodySizeLimitMiddleware;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Http\CountingStream;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
#[CoversClass(RequestBodySizeLimitMiddleware::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class RequestBodySizeLimitMiddlewareTest extends AbstractMcpTestCase
{
    public function testPassesThroughBodyUnderLimit(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(1_024)->process(self::request(512), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsBodyExactlyAtLimit(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(1_024)->process(self::request(1_024), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsBodyOverLimitWith413(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(1_024)->process(self::request(1_025), $handler);

        self::assertFalse($handler->called);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('2.0', $payload['jsonrpc'] ?? null);
        self::assertArrayNotHasKey('id', $payload);
        self::assertIsArray($payload['error'] ?? null);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $payload['error']['code'] ?? null);
        self::assertSame('The request body exceeds the permitted size.', $payload['error']['message'] ?? null);
    }

    public function testAReportedOversizeIsRejectedWithoutReadingTheBody(): void
    {
        $handler = self::recordingHandler();
        $stream = new CountingStream(str_repeat('a', 2_048), reportedSize: 2_048);

        $response = self::middleware(1_024)->process(self::requestWithBody($stream), $handler);

        self::assertFalse($handler->called);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(0, $stream->bytesRead);
    }

    public function testAnUnknownSizeBodyOverTheCapIsRejectedWithoutBufferingIt(): void
    {
        $handler = self::recordingHandler();
        $stream = new CountingStream(str_repeat('a', 8 * 1_024 * 1_024));

        $response = self::middleware(1_024)->process(self::requestWithBody($stream), $handler);

        self::assertFalse($handler->called);
        self::assertSame(413, $response->getStatusCode());
        self::assertLessThanOrEqual(1_025, $stream->bytesRead);
    }

    public function testAnUnknownSizeBodyAtTheCapReachesTheHandlerWhole(): void
    {
        $handler = self::recordingHandler();
        $content = str_repeat('a', 1_024);

        $response = self::middleware(1_024)->process(self::requestWithBody(new CountingStream($content)), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($handler->received);
        self::assertSame($content, (string) $handler->received->getBody());
    }

    public function testAnUnknownSizeBodyUnderTheCapReachesTheHandlerWhole(): void
    {
        $handler = self::recordingHandler();

        self::middleware(1_024)->process(self::requestWithBody(new CountingStream('hello')), $handler);

        self::assertNotNull($handler->received);
        self::assertSame('hello', (string) $handler->received->getBody());
    }

    public function testAStalledUnknownSizeBodyPassesWhatItServed(): void
    {
        $handler = self::recordingHandler();
        $stream = new CountingStream(str_repeat('a', 512), stallAfterBytes: 10);

        $response = self::middleware(1_024)->process(self::requestWithBody($stream), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($handler->received);
        self::assertSame(str_repeat('a', 10), (string) $handler->received->getBody());
    }

    public function testRejectsANegativeLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The maximum request body size must be a non-negative integer, -1 given.');

        // @phpstan-ignore argument.type (deliberately malformed to exercise the runtime guard)
        self::middleware(-1);
    }

    /**
     * @param int<0, max> $maxBytes
     */
    private static function middleware(int $maxBytes): RequestBodySizeLimitMiddleware
    {
        $factory = new Psr17Factory();

        return new RequestBodySizeLimitMiddleware($maxBytes, $factory, $factory);
    }

    private static function request(int $bytes): ServerRequestInterface
    {
        return self::requestWithBody((new Psr17Factory())->createStream(str_repeat('a', $bytes)));
    }

    private static function requestWithBody(StreamInterface $body): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('POST', 'https://mcp.test/')->withBody($body);
    }

    private static function recordingHandler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler((new Psr17Factory())->createResponse(200));
    }
}
