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
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
#[CoversClass(DnsRebindingProtectionMiddleware::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class DnsRebindingProtectionMiddlewareTest extends TestCase
{
    public function testPassesThroughWhenNoOriginHeader(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['https://app.test'])->process(self::request(), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPassesThroughAnAllowlistedOrigin(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['https://app.test', 'http://localhost:3000'])
            ->process(self::request('http://localhost:3000'), $handler)
        ;

        self::assertTrue($handler->called);
        self::assertSame('http://localhost:3000', $handler->received?->getHeaderLine('Origin'));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAllowsAnyOriginWhenWildcardConfigured(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['*'])->process(self::request('https://evil.test'), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsAnUnlistedOriginWith403(): void
    {
        $handler = self::recordingHandler();

        $response = self::middleware(['https://app.test'])->process(self::request('https://evil.test'), $handler);

        self::assertFalse($handler->called);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('2.0', $payload['jsonrpc'] ?? null);
        self::assertArrayNotHasKey('id', $payload);
        self::assertIsArray($payload['error'] ?? null);
        self::assertSame(ProtocolErrorCode::InvalidRequest->value, $payload['error']['code'] ?? null);
        self::assertSame('The request Origin is not allowed.', $payload['error']['message'] ?? null);
    }

    /**
     * @param list<non-empty-string> $allowedOrigins
     */
    private static function middleware(array $allowedOrigins): DnsRebindingProtectionMiddleware
    {
        $factory = new Psr17Factory();

        return new DnsRebindingProtectionMiddleware($allowedOrigins, $factory, $factory);
    }

    private static function request(?string $origin = null): ServerRequestInterface
    {
        $request = new Psr17Factory()->createServerRequest('POST', 'https://mcp.test/');

        return null === $origin ? $request : $request->withHeader('Origin', $origin);
    }

    private static function recordingHandler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler(new Psr17Factory()->createResponse(200));
    }
}
