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

use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Auth\AccessTokenValidatorInterface;
use Nexus\Mcp\Server\Tool\ToolStoreInterface;
use Nexus\Mcp\Server\Transport\Http\Middleware\BearerAuthenticationMiddleware;
use Nexus\Mcp\Server\Transport\Http\SecuredHttpEndpoint;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Server\Tool\PagedToolStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

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

    public function testValidatesParameterHeadersWhenAToolStoreIsGiven(): void
    {
        $handler = self::handler();

        $response = self::endpoint($handler, ['*'], toolStore: self::toolStore())->handle(self::toolCall('us-east1'));

        self::assertFalse($handler->called);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testOmitsParameterHeaderValidationByDefault(): void
    {
        $handler = self::handler();

        $response = self::endpoint($handler, ['*'])->handle(self::toolCall('us-east1'));

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAnswersRebindingAheadOfParameterHeaderValidation(): void
    {
        // The cheap origin gate must run before the body is peeked at.
        $handler = self::handler();

        $response = self::endpoint($handler, ['https://app.test'], toolStore: self::toolStore())
            ->handle(self::toolCall('us-east1')->withHeader('Origin', 'https://evil.test'))
        ;

        self::assertFalse($handler->called);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnUnauthenticatedRequestIsTurnedAwayBeforeTheHandler(): void
    {
        $handler = self::handler();

        $response = self::endpoint($handler, ['https://app.test'], authentication: self::authentication())
            ->handle(self::request(null))
        ;

        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('Bearer ', $response->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($handler->called);
    }

    public function testAnAuthenticatedRequestReachesTheHandler(): void
    {
        $handler = self::handler();

        $response = self::endpoint($handler, ['https://app.test'], authentication: self::authentication())
            ->handle(self::request(null)->withHeader('Authorization', 'Bearer the-token'))
        ;

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->called);
    }

    public function testADisallowedOriginIsRejectedBeforeAuthentication(): void
    {
        $handler = self::handler();

        $response = self::endpoint($handler, ['https://app.test'], authentication: self::authentication())
            ->handle(self::request('https://evil.test'))
        ;

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($handler->called);
    }

    /**
     * @param list<non-empty-string> $allowedOrigins
     * @param list<non-empty-string> $allowedHosts
     * @param null|int<0, max>       $maxBodyBytes
     */
    private static function endpoint(
        RecordingRequestHandler $handler,
        array $allowedOrigins,
        array $allowedHosts = [],
        ?int $maxBodyBytes = null,
        ?ToolStoreInterface $toolStore = null,
        ?BearerAuthenticationMiddleware $authentication = null,
    ): SecuredHttpEndpoint {
        $factory = new Psr17Factory();

        return new SecuredHttpEndpoint(
            $handler,
            $allowedOrigins,
            $factory,
            $factory,
            $allowedHosts,
            $maxBodyBytes,
            $toolStore,
            new NullLogger(),
            $authentication,
        );
    }

    private static function authentication(): BearerAuthenticationMiddleware
    {
        $recognised = new VerifiedAccessToken(['https://mcp.test/']);
        $validator = self::createStub(AccessTokenValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(
            static fn(string $presented): ?VerifiedAccessToken => 'the-token' === $presented ? $recognised : null,
        );

        return new BearerAuthenticationMiddleware(
            $validator,
            'https://mcp.test/',
            'https://mcp.test/.well-known/oauth-protected-resource',
            new Psr17Factory(),
        );
    }

    private static function toolStore(): ToolStoreInterface
    {
        return new PagedToolStore([[
            new Tool(name: 'echo', inputSchema: [
                'type' => 'object',
                'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']],
            ]),
        ]]);
    }

    /**
     * A `tools/call` whose `Mcp-Param-Region` header disagrees with its body argument.
     */
    private static function toolCall(string $header): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        return $factory->createServerRequest('POST', 'https://mcp.test/')
            ->withHeader('Mcp-Param-Region', $header)
            ->withBody($factory->createStream(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'echo', 'arguments' => ['region' => 'eu-west1']],
            ], \JSON_THROW_ON_ERROR)))
        ;
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
