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

use Nexus\Mcp\Core\Auth\VerifiedAccessToken;
use Nexus\Mcp\Core\Auth\WwwAuthenticateChallenge;
use Nexus\Mcp\Core\Exception\LogicException;
use Nexus\Mcp\Server\Transport\Http\Middleware\OperationScopeMiddleware;
use Nexus\Mcp\Server\Transport\StreamableHttpServerTransport;
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
#[CoversClass(OperationScopeMiddleware::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class OperationScopeMiddlewareTest extends AbstractMcpTestCase
{
    private const string RESOURCE = 'https://mcp.test/mcp';
    private const string METADATA_URL = 'https://mcp.test/.well-known/oauth-protected-resource/mcp';

    /**
     * @param non-empty-string     $method
     * @param array<string, mixed> $params
     */
    #[DataProvider('provideEachScopedOperationCases')]
    public function testATokenHoldingTheScopesReachesTheHandler(string $method, array $params): void
    {
        $handler = $this->buildHandler();
        $request = $this->buildRequest($this->encodeOperation($method, $params), ['files:write', 'files:read', 'profile']);

        $response = $this->buildMiddleware()->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->called);
    }

    /**
     * @param non-empty-string     $method
     * @param array<string, mixed> $params
     */
    #[DataProvider('provideEachScopedOperationCases')]
    public function testATokenShortOfTheScopesIsChallengedWithEveryScopeTheOperationNeeds(string $method, array $params): void
    {
        $handler = $this->buildHandler();
        $request = $this->buildRequest($this->encodeOperation($method, $params), ['files:read']);

        $response = $this->buildMiddleware()->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertSame([
            'resource_metadata' => self::METADATA_URL,
            'error' => 'insufficient_scope',
            'scope' => 'files:read files:write',
        ], $this->readChallenge($response));
    }

    /**
     * @param non-empty-string     $method
     * @param array<string, mixed> $params
     */
    #[DataProvider('provideEachScopedOperationCases')]
    public function testAScopedOperationReachingItWithNoValidatedTokenIsAWiringFault(string $method, array $params): void
    {
        $handler = $this->buildHandler();

        try {
            $this->buildMiddleware()->process($this->buildRequest($this->encodeOperation($method, $params), null), $handler);
            self::fail('The request should have been refused.');
        } catch (LogicException $e) {
            self::assertSame(
                'Operation scopes need the validated token an authentication middleware stores on the "nexus.mcp.access_token" request attribute, and none was found.',
                $e->getMessage(),
            );
            self::assertFalse($handler->called);
        }
    }

    /**
     * @return iterable<string, array{non-empty-string, array<string, mixed>}>
     */
    public static function provideEachScopedOperationCases(): iterable
    {
        yield 'a tool call' => ['tools/call', ['name' => 'write_file', 'arguments' => ['path' => 'a.txt']]];

        yield 'a prompt' => ['prompts/get', ['name' => 'release_notes']];

        yield 'a resource named by its URI' => ['resources/read', ['uri' => 'config://deploy']];

        yield 'a resource matching a URI template' => ['resources/read', ['uri' => 'file:///reports/q3.txt']];

        yield 'a resource whose literal part is percent-encoded' => ['resources/read', ['uri' => 'config://d%65ploy']];

        yield 'a template match whose literal part is percent-encoded' => ['resources/read', ['uri' => 'file:///%72eports/q3.txt']];

        yield 'a completion for a prompt' => ['completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'release_notes'],
            'argument' => ['name' => 'version', 'value' => '1'],
        ]];

        yield 'a completion for a resource template' => ['completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'file:///reports/{name}'],
            'argument' => ['name' => 'name', 'value' => 'q'],
        ]];

        yield 'a subscription to a resource' => ['subscriptions/listen', [
            'notifications' => ['resourceSubscriptions' => ['config://deploy']],
        ]];

        yield 'a subscription naming a scoped resource after others' => ['subscriptions/listen', [
            'notifications' => ['resourceSubscriptions' => [42, 'https://example.com/a', 'config://deploy']],
        ]];
    }

    #[DataProvider('provideAnOperationThatNeedsNoScopeReachesTheHandlerUntouchedCases')]
    public function testAnOperationThatNeedsNoScopeReachesTheHandlerUntouched(string $body): void
    {
        $handler = $this->buildHandler();

        $response = $this->buildMiddleware(endpointScopes: ['mcp:use'])->process($this->buildRequest($body, null), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($body, (string) $handler->received?->getBody());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnOperationThatNeedsNoScopeReachesTheHandlerUntouchedCases(): iterable
    {
        yield 'a tool nothing was declared for' => ['{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"read_file"}}'];

        yield 'a tool declared with no scopes' => ['{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"ping"}}'];

        yield 'a prompt nothing was declared for' => ['{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"greeting"}}'];

        yield 'a prompt sharing a scoped tool name' => ['{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"write_file"}}'];

        yield 'a tool sharing a scoped prompt name' => ['{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"release_notes"}}'];

        yield 'a resource no entry matches' => ['{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"https://example.com/a"}}'];

        yield 'a resource a template reaches only across a path segment' => ['{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"file:///reports/2026/q3.txt"}}'];

        yield 'a tool named like a scoped resource' => ['{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"config://deploy"}}'];

        yield 'a resource named like a scoped tool' => ['{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"write_file","name":"write_file"}}'];

        yield 'a method outside the five' => ['{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"name":"write_file","uri":"config://deploy"}}'];

        yield 'a method outside the five naming a scoped prompt' => ['{"jsonrpc":"2.0","id":1,"method":"prompts/list","params":{"name":"release_notes"}}'];

        yield 'an envelope naming no method' => ['{"jsonrpc":"2.0","id":1,"params":{"name":"write_file"}}'];

        yield 'an envelope carrying no params' => ['{"jsonrpc":"2.0","id":1,"method":"tools/call"}'];

        yield 'params that are not an object' => ['{"jsonrpc":"2.0","id":1,"method":"tools/call","params":"write_file"}'];

        yield 'a tool name that is not a string' => ['{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":["write_file"]}}'];

        yield 'a tool name that is a number' => ['{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":2048}}'];

        yield 'a prompt name that is not a string' => ['{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":["release_notes"]}}'];

        yield 'a resource URI that is not a string' => ['{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":["config://deploy"]}}'];

        yield 'a completion for a prompt nothing was declared for' => ['{"jsonrpc":"2.0","id":1,"method":"completion/complete","params":{"ref":{"type":"ref/prompt","name":"greeting"}}}'];

        yield 'a completion for a prompt sharing a scoped tool name' => ['{"jsonrpc":"2.0","id":1,"method":"completion/complete","params":{"ref":{"type":"ref/prompt","name":"write_file"}}}'];

        yield 'a completion for a resource template no entry matches' => ['{"jsonrpc":"2.0","id":1,"method":"completion/complete","params":{"ref":{"type":"ref/resource","uri":"https://example.com/{id}","name":"release_notes"}}}'];

        yield 'a completion whose reference is of another type' => ['{"jsonrpc":"2.0","id":1,"method":"completion/complete","params":{"ref":{"type":"ref/tool","name":"release_notes","uri":"config://deploy"}}}'];

        yield 'a completion whose reference is not an object' => ['{"jsonrpc":"2.0","id":1,"method":"completion/complete","params":{"ref":"release_notes"}}'];

        yield 'a completion naming a scoped prompt outside its reference' => ['{"jsonrpc":"2.0","id":1,"method":"completion/complete","params":{"name":"release_notes","uri":"config://deploy"}}'];

        yield 'a subscription to list changes only' => ['{"jsonrpc":"2.0","id":1,"method":"subscriptions/listen","params":{"notifications":{"toolsListChanged":true}}}'];

        yield 'a subscription to a resource no entry matches' => ['{"jsonrpc":"2.0","id":1,"method":"subscriptions/listen","params":{"notifications":{"resourceSubscriptions":["https://example.com/a"]}}}'];

        yield 'a subscription whose resources are not a list' => ['{"jsonrpc":"2.0","id":1,"method":"subscriptions/listen","params":{"notifications":{"resourceSubscriptions":"config://deploy"}}}'];

        yield 'a subscription whose filter is not an object' => ['{"jsonrpc":"2.0","id":1,"method":"subscriptions/listen","params":{"notifications":"config://deploy"}}'];

        yield 'a subscription naming a scoped resource outside its filter' => ['{"jsonrpc":"2.0","id":1,"method":"subscriptions/listen","params":{"resourceSubscriptions":["config://deploy"],"uri":"config://deploy"}}'];

        yield 'a body that is not an envelope' => ['"tools/call"'];

        yield 'a body that is not JSON' => ['{not json}'];

        yield 'an empty body' => [''];
    }

    public function testTheChallengeNamesTheEndpointScopesAheadOfTheOperationScopes(): void
    {
        $handler = $this->buildHandler();
        $request = $this->buildRequest(
            $this->encodeOperation('tools/call', ['name' => 'write_file']),
            ['files:read', 'files:write'],
        );

        $response = $this->buildMiddleware(endpointScopes: ['mcp:use'])->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($handler->called);
        self::assertSame('mcp:use files:read files:write', $this->readChallenge($response)['scope'] ?? null);
    }

    public function testATokenHoldingTheEndpointAndOperationScopesReachesTheHandler(): void
    {
        $handler = $this->buildHandler();
        $request = $this->buildRequest(
            $this->encodeOperation('tools/call', ['name' => 'write_file']),
            ['mcp:use', 'files:read', 'files:write'],
        );

        $response = $this->buildMiddleware(endpointScopes: ['mcp:use'])->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->called);
    }

    public function testAResourceMatchingSeveralEntriesNeedsTheScopesOfEachInDeclarationOrder(): void
    {
        $handler = $this->buildHandler();
        $middleware = $this->buildMiddleware(resources: [
            'file:///{name}' => ['files:read'],
            'https://example.com/{name}' => ['web:read'],
            'file:///secret.txt' => ['files:secret', 'files:read'],
        ]);
        $request = $this->buildRequest($this->encodeOperation('resources/read', ['uri' => 'file:///secret.txt']), ['files:read']);

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('files:read files:secret', $this->readChallenge($response)['scope'] ?? null);
    }

    public function testAKeyWrittenWithAPercentEscapeMatchesTheUriAsSent(): void
    {
        $handler = $this->buildHandler();
        $middleware = $this->buildMiddleware(resources: ['file:///my%20notes/{name}' => ['notes:read']]);
        $request = $this->buildRequest($this->encodeOperation('resources/read', ['uri' => 'file:///my%20notes/a.txt']), []);

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('notes:read', $this->readChallenge($response)['scope'] ?? null);
    }

    public function testASubscriptionNeedsTheScopesOfEveryResourceItNames(): void
    {
        $handler = $this->buildHandler();
        $middleware = $this->buildMiddleware(resources: [
            'config://deploy' => ['deploy:read'],
            'file:///{name}' => ['files:read'],
        ]);
        $request = $this->buildRequest($this->encodeOperation('subscriptions/listen', [
            'notifications' => ['resourceSubscriptions' => ['file:///a.txt', 'config://deploy']],
        ]), ['files:read']);

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('files:read deploy:read', $this->readChallenge($response)['scope'] ?? null);
    }

    /**
     * @param array<int|non-empty-string, list<non-empty-string>> $tools
     */
    #[DataProvider('provideANameMadeOfDigitsIsScopedCases')]
    public function testANameMadeOfDigitsIsScoped(array $tools): void
    {
        $handler = $this->buildHandler();
        $factory = new Psr17Factory();
        $middleware = new OperationScopeMiddleware(self::METADATA_URL, $factory, $factory, $tools);
        $request = $this->buildRequest($this->encodeOperation('tools/call', ['name' => '2048']), []);

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('game:play', $this->readChallenge($response)['scope'] ?? null);
    }

    /**
     * @return iterable<string, array{array<int|non-empty-string, list<non-empty-string>>}>
     */
    public static function provideANameMadeOfDigitsIsScopedCases(): iterable
    {
        yield 'declared as a string' => [['2048' => ['game:play']]];

        yield 'declared as an integer' => [[2_048 => ['game:play']]];
    }

    public function testAResourceKeyMadeOfDigitsIsMatchedAsItsText(): void
    {
        $handler = $this->buildHandler();
        $request = $this->buildRequest($this->encodeOperation('resources/read', ['uri' => '2048']), []);

        $response = $this->buildMiddleware(resources: [2_048 => ['game:read']])->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('game:read', $this->readChallenge($response)['scope'] ?? null);
    }

    public function testTheDecodedEnvelopeTravelsOnTheRequest(): void
    {
        $handler = $this->buildHandler();
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';

        $this->buildMiddleware()->process($this->buildRequest($body, null), $handler);

        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            $handler->received?->getAttribute(StreamableHttpServerTransport::ENVELOPE_ATTRIBUTE),
        );
    }

    public function testABodyThatDecodesToNoEnvelopeLeavesNoneOnTheRequest(): void
    {
        $handler = $this->buildHandler();

        $this->buildMiddleware()->process($this->buildRequest('"tools/call"', null), $handler);

        self::assertNull($handler->received?->getAttribute(StreamableHttpServerTransport::ENVELOPE_ATTRIBUTE));
    }

    /**
     * @param array<array-key, mixed> $tools
     * @param array<array-key, mixed> $prompts
     * @param array<array-key, mixed> $resources
     * @param array<array-key, mixed> $endpointScopes
     */
    #[DataProvider('provideAMalformedDeclarationIsRefusedCases')]
    public function testAMalformedDeclarationIsRefused(array $tools, array $prompts, array $resources, array $endpointScopes, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs($expectedMessage);

        // @phpstan-ignore argument.type, argument.type, argument.type, argument.type (deliberately malformed to exercise the runtime guards)
        new OperationScopeMiddleware(self::METADATA_URL, new Psr17Factory(), new Psr17Factory(), $tools, $prompts, $resources, $endpointScopes);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>, array<array-key, mixed>, array<array-key, mixed>, string}>
     */
    public static function provideAMalformedDeclarationIsRefusedCases(): iterable
    {
        yield 'a tool keyed by an empty name' => [
            ['' => ['files:write']], [], [], [],
            'each operation scope tool key must be an int or non-empty string, \'\' given.',
        ];

        yield 'a prompt keyed by an empty name' => [
            [], ['' => ['files:write']], [], [],
            'each operation scope prompt key must be an int or non-empty string, \'\' given.',
        ];

        yield 'a resource keyed by an empty URI' => [
            [], [], ['' => ['files:write']], [],
            'each operation scope resource key must be an int or non-empty string, \'\' given.',
        ];

        yield 'tool scopes that are not a list' => [
            ['write_file' => ['scope' => 'files:write']], [], [], [],
            'each operation scope tool entry must be a list of scopes, array given.',
        ];

        yield 'prompt scopes that are not an array' => [
            [], ['release_notes' => 'files:write'], [], [],
            'each operation scope prompt entry must be a list of scopes, string given.',
        ];

        yield 'resource scopes under a key holding an assertion token' => [
            [], [], ['repo://{type}/{value}' => 'repo:read'], [],
            'each operation scope resource entry must be a list of scopes, string given.',
        ];

        yield 'two scopes joined into one entry' => [
            ['write_file' => ['files:read files:write']], [], [], [],
            'each operation scope must be an RFC 6749 scope-token, \'files:read files:write\' given.',
        ];

        yield 'an empty scope' => [
            [], ['release_notes' => ['']], [], [],
            'each operation scope must be an RFC 6749 scope-token, \'\' given.',
        ];

        yield 'a scope carrying a quote' => [
            [], [], ['config://deploy' => ['files:"read"']], [],
            'each operation scope must be an RFC 6749 scope-token, \'files:"read"\' given.',
        ];

        yield 'an endpoint scope carrying a space' => [
            [], [], [], ['mcp use'],
            'each operation scope must be an RFC 6749 scope-token, \'mcp use\' given.',
        ];

        yield 'a resource template with adjacent expressions' => [
            [], [], ['file:///{a}{b}' => ['files:read']], [],
            'Operation scope resource URI template must include literal text between adjacent expressions, got "file:///{a}{b}".',
        ];
    }

    /**
     * @param null|array<int|non-empty-string, list<non-empty-string>> $resources
     * @param list<non-empty-string>                                   $endpointScopes
     */
    private function buildMiddleware(?array $resources = null, array $endpointScopes = []): OperationScopeMiddleware
    {
        $factory = new Psr17Factory();

        return new OperationScopeMiddleware(
            self::METADATA_URL,
            $factory,
            $factory,
            tools: ['write_file' => ['files:read', 'files:write'], 'ping' => [], '2048' => ['game:play']],
            prompts: ['release_notes' => ['files:read', 'files:write']],
            resources: $resources ?? [
                'config://deploy' => ['files:read', 'files:write'],
                'file:///reports/{name}' => ['files:read', 'files:write'],
            ],
            endpointScopes: $endpointScopes,
        );
    }

    /**
     * @param non-empty-string     $method
     * @param array<string, mixed> $params
     */
    private function encodeOperation(string $method, array $params): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param null|list<non-empty-string> $grantedScopes `null` for a request no token travels on
     */
    private function buildRequest(string $body, ?array $grantedScopes): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', self::RESOURCE)->withBody($factory->createStream($body));

        if (null === $grantedScopes) {
            return $request;
        }

        return $request->withAttribute(
            VerifiedAccessToken::REQUEST_ATTRIBUTE,
            new VerifiedAccessToken([self::RESOURCE], 2_000_000_000, $grantedScopes),
        );
    }

    private function buildHandler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler((new Psr17Factory())->createResponse(200));
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function readChallenge(ResponseInterface $response): array
    {
        $challenge = WwwAuthenticateChallenge::findBearer($response->getHeaderLine('WWW-Authenticate'));

        self::assertInstanceOf(WwwAuthenticateChallenge::class, $challenge);

        return $challenge->parameters;
    }
}
