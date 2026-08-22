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
use Nexus\Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
#[CoversClass(DnsRebindingProtectionMiddleware::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class DnsRebindingProtectionMiddlewareTest extends AbstractMcpTestCase
{
    public function testPassesThroughWhenNoOriginHeader(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['https://app.test'])->process($this->request(), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPassesThroughAnAllowlistedOrigin(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['https://app.test', 'http://localhost:3000'])
            ->process($this->request('http://localhost:3000'), $handler)
        ;

        self::assertTrue($handler->called);
        self::assertSame('http://localhost:3000', $handler->received?->getHeaderLine('Origin'));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsAnyOriginWhenWildcardConfigured(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['*'])->process($this->request('https://evil.test'), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsAnUnlistedOriginWith403(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['https://app.test'])->process($this->request('https://evil.test'), $handler);

        self::assertFalse($handler->called);
        $this->assertRejectedWith($response, 'The request Origin is not allowed.');
    }

    public function testAllowsAnAllowlistedHost(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['*'], ['mcp.test'])->process($this->request(), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsAnyHostWhenWildcardConfigured(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['*'], ['*'])->process($this->request(), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsAnUnlistedHostWith403(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['*'], ['app.test'])->process($this->request(), $handler);

        self::assertFalse($handler->called);
        $this->assertRejectedWith($response, 'The request Host is not allowed.');
    }

    /**
     * @param list<non-empty-string> $allowedHosts
     */
    #[DataProvider('provideMatchesTheHostCaseInsensitivelyCases')]
    public function testMatchesTheHostCaseInsensitively(string $host, array $allowedHosts): void
    {
        // RFC 9110 makes a URI's host case-insensitive, and some proxies do rewrite its case.
        $handler = $this->recordingHandler();

        $response = $this->middleware(['*'], $allowedHosts)
            ->process($this->request()->withHeader('Host', $host), $handler)
        ;

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, list<non-empty-string>}>
     */
    public static function provideMatchesTheHostCaseInsensitivelyCases(): iterable
    {
        yield 'mixed-case header' => ['MCP.Example.com', ['mcp.example.com']];

        yield 'mixed-case allow-list entry' => ['mcp.example.com', ['MCP.Example.com']];

        yield 'both mixed' => ['MCP.example.COM', ['mcp.EXAMPLE.com']];
    }

    /**
     * @param list<non-empty-string> $allowedOrigins
     */
    #[DataProvider('provideMatchesTheOriginCaseInsensitivelyCases')]
    public function testMatchesTheOriginCaseInsensitively(string $origin, array $allowedOrigins): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware($allowedOrigins)->process($this->request($origin), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, list<non-empty-string>}>
     */
    public static function provideMatchesTheOriginCaseInsensitivelyCases(): iterable
    {
        yield 'mixed-case header' => ['HTTPS://App.Test', ['https://app.test']];

        yield 'mixed-case allow-list entry' => ['https://app.test', ['HTTPS://App.Test']];
    }

    public function testRejectsAMissingHostWhenValidationEnabled(): void
    {
        $handler = $this->recordingHandler();

        $response = $this->middleware(['*'], ['mcp.test'])
            ->process($this->request()->withoutHeader('Host'), $handler)
        ;

        self::assertFalse($handler->called);
        $this->assertRejectedWith($response, 'The request Host is not allowed.');
    }

    private function assertRejectedWith(ResponseInterface $response, string $message): void
    {
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('2.0', $payload['jsonrpc'] ?? null);
        self::assertArrayNotHasKey('id', $payload);
        self::assertIsArray($payload['error'] ?? null);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $payload['error']['code'] ?? null);
        self::assertSame($message, $payload['error']['message'] ?? null);
    }

    /**
     * @param list<non-empty-string> $allowedOrigins
     * @param list<non-empty-string> $allowedHosts
     */
    private function middleware(array $allowedOrigins, array $allowedHosts = []): DnsRebindingProtectionMiddleware
    {
        $factory = new Psr17Factory();

        return new DnsRebindingProtectionMiddleware($allowedOrigins, $allowedHosts, $factory, $factory);
    }

    private function request(?string $origin = null): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', 'https://mcp.test/');

        return null === $origin ? $request : $request->withHeader('Origin', $origin);
    }

    private function recordingHandler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler((new Psr17Factory())->createResponse(200));
    }
}
