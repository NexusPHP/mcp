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
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
#[CoversClass(CorsMiddleware::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CorsMiddlewareTest extends TestCase
{
    public function testPreflightFromAllowedOriginReturns204WithCorsHeaders(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['https://app.test'])
            ->process(self::preflightRequest('https://app.test', 'Content-Type, MCP-Protocol-Version'), $handler)
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
        $handler = self::recordingHandler();

        $response = self::middleware(['https://app.test'])
            ->process(self::preflightRequest('https://app.test'), $handler)
        ;

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Headers'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testPreflightFromDisallowedOriginReturns204WithoutGrant(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['https://app.test'])
            ->process(self::preflightRequest('https://evil.test'), $handler)
        ;

        self::assertFalse($handler->called);
        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
        self::assertFalse($response->hasHeader('Access-Control-Max-Age'));
        self::assertFalse($response->hasHeader('Vary'));
    }

    public function testPreflightWithWildcardReflectsRequestOrigin(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['*'])
            ->process(self::preflightRequest('https://any.test'), $handler)
        ;

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://any.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testPreflightMaxAgeReflectsConfiguredValue(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['https://app.test'], 120)
            ->process(self::preflightRequest('https://app.test'), $handler)
        ;

        self::assertSame('120', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testDecoratesResponseForAllowedOrigin(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['https://app.test'])
            ->process(self::request('POST', 'https://app.test'), $handler)
        ;

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testDoesNotDecorateResponseForDisallowedOrigin(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['https://app.test'])
            ->process(self::request('POST', 'https://evil.test'), $handler)
        ;

        self::assertTrue($handler->called);
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Vary'));
    }

    public function testPassesThroughRequestWithoutOrigin(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['*'])->process(self::request(), $handler);

        self::assertTrue($handler->called);
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Vary'));
    }

    public function testOptionsWithoutRequestedMethodIsNotPreflight(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['https://app.test'])
            ->process(self::request('OPTIONS', 'https://app.test'), $handler)
        ;

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    /**
     * @param list<non-empty-string> $allowedOrigins
     */
    private static function middleware(array $allowedOrigins, ?int $maxAge = null): CorsMiddleware
    {
        $factory = new Psr17Factory();

        return null === $maxAge
            ? new CorsMiddleware($allowedOrigins, $factory)
            : new CorsMiddleware($allowedOrigins, $factory, $maxAge);
    }

    private static function preflightRequest(?string $origin, ?string $requestedHeaders = null): ServerRequestInterface
    {
        $request = new Psr17Factory()->createServerRequest('OPTIONS', 'https://mcp.test/')
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

    private static function request(string $method = 'POST', ?string $origin = null): ServerRequestInterface
    {
        $request = new Psr17Factory()->createServerRequest($method, 'https://mcp.test/');

        return null === $origin ? $request : $request->withHeader('Origin', $origin);
    }

    private static function recordingHandler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler(new Psr17Factory()->createResponse(200));
    }
}
