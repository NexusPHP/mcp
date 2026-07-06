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

namespace Nexus\Mcp\Tests\Server\Transport\Http;

use Nexus\Mcp\Server\Transport\Http\SecuredHttpEndpoint;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
#[CoversClass(SecuredHttpEndpoint::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class SecuredHttpEndpointTest extends TestCase
{
    public function testForwardsAnAllowedRequestAndDecoratesTheResponse(): void
    {
        $handler = self::handler();

        $response = self::endpoint($handler, ['https://app.test'])->handle(self::request('https://app.test'));

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testRejectsADisallowedOriginWith403(): void
    {
        $handler = self::handler();

        $response = self::endpoint($handler, ['https://app.test'])->handle(self::request('https://evil.test'));

        self::assertFalse($handler->called);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnswersPreflightAheadOfTheRebindingGate(): void
    {
        $handler = self::handler();

        $response = self::endpoint($handler, ['https://app.test'])->handle(self::preflight('https://evil.test'));

        self::assertFalse($handler->called);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testRejectsADisallowedHostWith403(): void
    {
        $handler = self::handler();

        $response = self::endpoint($handler, ['*'], ['app.test'])->handle(self::request(null));

        self::assertFalse($handler->called);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testEnforcesTheBodySizeCapWhenConfigured(): void
    {
        $handler = self::handler();

        $response = self::endpoint($handler, ['*'], maxBodyBytes: 1024)->handle(self::request(null, 2048));

        self::assertFalse($handler->called);
        self::assertSame(413, $response->getStatusCode());
    }

    public function testOmitsTheBodySizeCapByDefault(): void
    {
        $handler = self::handler();

        $response = self::endpoint($handler, ['*'])->handle(self::request(null, 2048));

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param list<non-empty-string> $allowedOrigins
     * @param list<non-empty-string> $allowedHosts
     */
    private static function endpoint(
        RecordingRequestHandler $handler,
        array $allowedOrigins,
        array $allowedHosts = [],
        ?int $maxBodyBytes = null,
    ): SecuredHttpEndpoint {
        $factory = new Psr17Factory();

        return new SecuredHttpEndpoint($handler, $allowedOrigins, $factory, $factory, $allowedHosts, $maxBodyBytes);
    }

    private static function handler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler(new Psr17Factory()->createResponse(200));
    }

    private static function request(?string $origin, int $bodyBytes = 0): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://mcp.test/');

        if (null !== $origin) {
            $request = $request->withHeader('Origin', $origin);
        }

        if ($bodyBytes > 0) {
            $request = $request->withBody($factory->createStream(str_repeat('a', $bodyBytes)));
        }

        return $request;
    }

    private static function preflight(string $origin): ServerRequestInterface
    {
        return new Psr17Factory()->createServerRequest('OPTIONS', 'https://mcp.test/')
            ->withHeader('Origin', $origin)
            ->withHeader('Access-Control-Request-Method', 'POST')
        ;
    }
}
