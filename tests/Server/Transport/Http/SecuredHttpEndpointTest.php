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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Server\Http\NonSeekableStream;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Server\Tool\PagedToolStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * @internal
 */
#[CoversClass(SecuredHttpEndpoint::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class SecuredHttpEndpointTest extends AbstractMcpTestCase
{
    public function testForwardsAnAllowedRequestAndDecoratesTheResponse(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['https://app.test'])->handle($this->buildRequest('https://app.test'));

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testRejectsADisallowedOriginWith403(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['https://app.test'])->handle($this->buildRequest('https://evil.test'));

        self::assertFalse($handler->called);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnswersPreflightAheadOfTheRebindingGate(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['https://app.test'])->handle($this->buildPreflight('https://evil.test'));

        self::assertFalse($handler->called);
        self::assertSame(204, $response->getStatusCode());
    }

    public function testRejectsADisallowedHostWith403(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['*'], ['app.test'])->handle($this->buildRequest(null));

        self::assertFalse($handler->called);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testEnforcesTheBodySizeCapWhenConfigured(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['*'], maxBodyBytes: 1_024)->handle($this->buildRequest(null, 2_048));

        self::assertFalse($handler->called);
        self::assertSame(413, $response->getStatusCode());
    }

    public function testABodyOfUnknownSizeIsHeldToTheCap(): void
    {
        $handler = $this->buildHandler();
        $request = $this->buildRequest(null)->withBody(new NonSeekableStream(str_repeat('x', 2_048)));

        $response = $this->buildEndpoint($handler, ['*'], maxBodyBytes: 1_024)->handle($request);

        self::assertFalse($handler->called, 'The cap measures an unreported size by reading past it.');
        self::assertSame(413, $response->getStatusCode());
    }

    public function testABodyOfUnknownSizeIsHeldToTheCapWithAToolStoreToo(): void
    {
        $handler = $this->buildHandler();
        $request = $this->buildRequest(null)->withBody(new NonSeekableStream(str_repeat('x', 2_048)));

        $response = $this->buildEndpoint($handler, ['*'], maxBodyBytes: 1_024, toolStore: $this->buildToolStore())
            ->handle($request)
        ;

        self::assertFalse($handler->called, 'The cap answers before header validation reads the body.');
        self::assertSame(413, $response->getStatusCode());
    }

    public function testCapsTheBodyAtOneMebibyteByDefault(): void
    {
        $handler = $this->buildHandler();
        $endpoint = $this->buildEndpoint($handler, ['*']);

        self::assertSame(200, $endpoint->handle($this->buildRequest(null, 1_048_576))->getStatusCode());
        self::assertTrue($handler->called);

        $handler->called = false;

        self::assertSame(413, $endpoint->handle($this->buildRequest(null, 1_048_577))->getStatusCode());
        self::assertFalse($handler->called);
    }

    public function testANullCapDisablesTheBodySizeLimit(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['*'], maxBodyBytes: null)->handle($this->buildRequest(null, 1_048_577));

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testValidatesParameterHeadersWhenAToolStoreIsGiven(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['*'], toolStore: $this->buildToolStore())->handle($this->buildMismatchedToolCall('us-east1'));

        self::assertFalse($handler->called);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testOmitsParameterHeaderValidationByDefault(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['*'])->handle($this->buildMismatchedToolCall('us-east1'));

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAnswersRebindingAheadOfParameterHeaderValidation(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['https://app.test'], toolStore: $this->buildToolStore())
            ->handle($this->buildMismatchedToolCall('us-east1')->withHeader('Origin', 'https://evil.test'))
        ;

        self::assertFalse($handler->called);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnswersTheBodySizeCapAheadOfParameterHeaderValidation(): void
    {
        $handler = $this->buildHandler();
        $store = $this->buildToolStore();

        $response = $this->buildEndpoint($handler, ['*'], maxBodyBytes: 16, toolStore: $store)
            ->handle($this->buildMismatchedToolCall('us-east1'))
        ;

        self::assertFalse($handler->called);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(0, $store->listCalls, 'An oversized body is refused before anything decodes it.');
    }

    public function testAnUnauthenticatedRequestIsTurnedAwayBeforeTheHandler(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['https://app.test'], authentication: $this->buildAuthentication())
            ->handle($this->buildRequest(null))
        ;

        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('Bearer ', $response->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($handler->called);
    }

    public function testAnAuthenticatedRequestReachesTheHandler(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['https://app.test'], authentication: $this->buildAuthentication())
            ->handle($this->buildRequest(null)->withHeader('Authorization', 'Bearer the-token'))
        ;

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->called);
    }

    public function testADisallowedOriginIsRejectedBeforeAuthentication(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['https://app.test'], authentication: $this->buildAuthentication())
            ->handle($this->buildRequest('https://evil.test'))
        ;

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($handler->called);
    }

    public function testAnOversizedBodyIsRefusedOnlyAfterAuthentication(): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildEndpoint($handler, ['*'], maxBodyBytes: 16, authentication: $this->buildAuthentication())
            ->handle($this->buildRequest(null, 2_048))
        ;

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($handler->called);
    }

    /**
     * @param list<non-empty-string> $allowedOrigins
     * @param list<non-empty-string> $allowedHosts
     * @param null|int<0, max>       $maxBodyBytes
     */
    private function buildEndpoint(
        RecordingRequestHandler $handler,
        array $allowedOrigins,
        array $allowedHosts = [],
        ?int $maxBodyBytes = SecuredHttpEndpoint::DEFAULT_MAX_BODY_BYTES,
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

    private function buildAuthentication(): BearerAuthenticationMiddleware
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

    private function buildToolStore(): PagedToolStore
    {
        return new PagedToolStore([[
            new Tool(name: 'echo', inputSchema: [
                'type' => 'object',
                'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']],
            ]),
        ]]);
    }

    private function buildMismatchedToolCall(string $header): ServerRequestInterface
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

    private function buildHandler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler((new Psr17Factory())->createResponse(200));
    }

    private function buildRequest(?string $origin, int $bodyBytes = 0): ServerRequestInterface
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

    private function buildPreflight(string $origin): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('OPTIONS', 'https://mcp.test/')
            ->withHeader('Origin', $origin)
            ->withHeader('Access-Control-Request-Method', 'POST')
        ;
    }
}
