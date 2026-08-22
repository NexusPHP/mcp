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

use Nexus\Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
#[CoversClass(CorsMiddleware::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CorsMiddlewareTest extends AbstractMcpTestCase
{
    public function testPreflightFromAllowedOriginReturns204WithCorsHeaders(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['https://app.test'])
            ->process($this->preflightRequest('https://app.test', 'Content-Type, MCP-Protocol-Version'), $handler)
        ;

        self::assertFalse($handler->called);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('POST, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, MCP-Protocol-Version', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
        self::assertSame('Origin, Access-Control-Request-Headers', $response->getHeaderLine('Vary'));
    }

    public function testPreflightWithoutRequestedHeadersOmitsAllowHeaders(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['https://app.test'])
            ->process($this->preflightRequest('https://app.test'), $handler)
        ;

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Headers'));
        self::assertSame('Origin, Access-Control-Request-Headers', $response->getHeaderLine('Vary'));
    }

    public function testPreflightFromDisallowedOriginReturns204WithoutGrant(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['https://app.test'])
            ->process($this->preflightRequest('https://evil.test'), $handler)
        ;

        self::assertFalse($handler->called);
        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
        self::assertFalse($response->hasHeader('Access-Control-Max-Age'));
        self::assertSame('Origin, Access-Control-Request-Headers', $response->getHeaderLine('Vary'), 'A refused preflight is still keyed on the headers it turns on.');
    }

    public function testPreflightWithWildcardReflectsRequestOrigin(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['*'])
            ->process($this->preflightRequest('https://any.test'), $handler)
        ;

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://any.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testPreflightMaxAgeReflectsConfiguredValue(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['https://app.test'], 120)
            ->process($this->preflightRequest('https://app.test'), $handler)
        ;

        self::assertSame('120', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testDecoratesResponseForAllowedOrigin(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['https://app.test'])
            ->process($this->request('POST', 'https://app.test'), $handler)
        ;

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testDoesNotDecorateResponseForDisallowedOrigin(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['https://app.test'])
            ->process($this->request('POST', 'https://evil.test'), $handler)
        ;

        self::assertTrue($handler->called);
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'), 'A refused response is still keyed on Origin.');
    }

    public function testPassesThroughRequestWithoutOrigin(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['*'])->process($this->request(), $handler);

        self::assertTrue($handler->called);
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'), 'An origin-less response is still keyed, so it is not replayed to an allowed origin.');
    }

    public function testOptionsWithoutRequestedMethodIsNotPreflight(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['https://app.test'])
            ->process($this->request('OPTIONS', 'https://app.test'), $handler)
        ;

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    /**
     * @param list<non-empty-string> $allowedOrigins
     */
    private function middleware(array $allowedOrigins, ?int $maxAge = null): CorsMiddleware
    {
        $factory = new Psr17Factory();

        return null === $maxAge
            ? new CorsMiddleware($allowedOrigins, $factory)
            : new CorsMiddleware($allowedOrigins, $factory, $maxAge);
    }

    private function preflightRequest(?string $origin, ?string $requestedHeaders = null): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('OPTIONS', 'https://mcp.test/')
            ->withHeader('Access-Control-Request-Method', 'POST')
        ;

        if (null !== $origin) {
            $request = $request->withHeader('Origin', $origin);
        }

        if (null !== $requestedHeaders) {
            $request = $request->withHeader('Access-Control-Request-Headers', $requestedHeaders);
        }

        return $request;
    }

    private function request(string $method = 'POST', ?string $origin = null): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, 'https://mcp.test/');

        return null === $origin ? $request : $request->withHeader('Origin', $origin);
    }

    private function recordingHandler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler((new Psr17Factory())->createResponse(200));
    }
}
