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
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Server\Transport\Http\Middleware\ParameterHeaderValidationMiddleware;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Server\Http\NonSeekableStream;
use Nexus\Mcp\Tests\Fixtures\Server\Http\RecordingRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Server\Tool\PagedToolStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LogLevel;

/**
 * @internal
 */
#[CoversClass(ParameterHeaderValidationMiddleware::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ParameterHeaderValidationMiddlewareTest extends AbstractMcpTestCase
{
    public function testForwardsAMatchingHeader(): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(
            self::callPost(['region' => 'us-west1'], ['Mcp-Param-Region' => 'us-west1']),
            $handler,
        );

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsAHeaderThatDisagreesWithTheBody(): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(
            self::callPost(['region' => 'eu-west1'], ['Mcp-Param-Region' => 'us-east1']),
            $handler,
        );

        self::assertFalse($handler->called, 'A split-truth request must never reach the transport.');
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::HeaderMismatch->value, self::errorPayload($response)['code'] ?? null);
    }

    public function testRejectsAnAbsentHeaderWhoseArgumentIsPresent(): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(self::callPost(['region' => 'us-west1']), $handler);

        self::assertFalse($handler->called);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ProtocolErrorCode::HeaderMismatch->value, self::errorPayload($response)['code'] ?? null);
    }

    public function testRejectionEchoesTheRequestId(): void
    {
        $response = self::middleware()->process(
            self::callPost(['region' => 'eu-west1'], ['Mcp-Param-Region' => 'us-east1'], id: 'call-7'),
            self::handler(),
        );

        self::assertSame('call-7', self::decode($response)['id'] ?? null);
    }

    public function testRejectionOmitsAMalformedRequestId(): void
    {
        $response = self::middleware()->process(
            self::callPost(['region' => 'eu-west1'], ['Mcp-Param-Region' => 'us-east1'], id: ['bad']),
            self::handler(),
        );

        self::assertArrayNotHasKey('id', self::decode($response));
    }

    public function testForwardsWhenTheArgumentIsAbsentSoNoHeaderIsExpected(): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(self::callPost([]), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testDecodesTheBase64SentinelBeforeComparing(): void
    {
        $handler = self::handler();
        $encoded = \sprintf('=?base64?%s?=', base64_encode('Hello, 世界'));

        $response = self::middleware()->process(
            self::callPost(['region' => 'Hello, 世界'], ['Mcp-Param-Region' => $encoded]),
            $handler,
        );

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testMatchesTheHeaderNameCaseInsensitively(): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(
            self::callPost(['region' => 'us-west1'], ['mcp-param-region' => 'us-west1']),
            $handler,
        );

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[DataProvider('provideForwardsBodiesItDoesNotGovernCases')]
    public function testForwardsBodiesItDoesNotGovern(array|string $body): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(self::post($body), $handler);

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function provideForwardsBodiesItDoesNotGovernCases(): iterable
    {
        yield 'undecodable body' => ['{"jsonrpc":'];

        yield 'json that is not an object' => ['[1, 2]'];

        yield 'another method' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']];

        // `prompts/get` also carries `params.name`. Only a tool call mirrors arguments into headers, so a
        // prompt sharing a bound tool's name must not be validated against that tool's bindings.
        yield 'another method whose params.name matches a bound tool' => [[
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'prompts/get',
            'params' => ['name' => 'echo', 'arguments' => ['region' => 'eu-west1']],
        ]];

        yield 'a tool with no declarations' => [[
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'plain', 'arguments' => ['x' => 'y']],
        ]];

        yield 'an unknown tool' => [[
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'nope', 'arguments' => ['region' => 'us-west1']],
        ]];

        yield 'a non-string tool name' => [[
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 42, 'arguments' => ['region' => 'us-west1']],
        ]];

        yield 'params that are not an object' => [[
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => 'oops',
        ]];
    }

    public function testLeavesTheBodyReadableForTheTransport(): void
    {
        $handler = self::handler();
        $request = self::callPost(['region' => 'us-west1'], ['Mcp-Param-Region' => 'us-west1']);

        self::middleware()->process($request, $handler);

        if (! $handler->received instanceof ServerRequestInterface) {
            self::fail('The inner handler was not reached.');
        }

        self::assertNotSame('', (string) $handler->received->getBody(), 'Peeking at the body must not consume it.');
    }

    public function testSkipsAndWarnsForAToolWithInvalidDeclarations(): void
    {
        $logger = new ArrayLogger();
        $handler = self::handler();
        $store = new PagedToolStore([[
            new Tool(name: 'broken', inputSchema: [
                // `number` is not a permitted type, so the declaration set cannot be trusted.
                'type' => 'object',
                'properties' => ['size' => ['type' => 'number', 'x-mcp-header' => 'Size']],
            ]),
        ]]);

        $response = (new ParameterHeaderValidationMiddleware($store, new Psr17Factory(), new Psr17Factory(), $logger))
            ->process(self::post([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'broken', 'arguments' => ['size' => 4]],
            ]), $handler)
        ;

        self::assertTrue($handler->called);
        self::assertSame(200, $response->getStatusCode());
        $matches = $logger->recordsMatching(LogLevel::WARNING, 'Skipping {tool} header validation: its "x-mcp-header" declarations are invalid.');
        self::assertCount(1, $matches);
        self::assertSame('broken', $matches[0]['context']['tool'] ?? null);
        self::assertIsString($matches[0]['context']['reason'] ?? null, 'The scan fault is reported so the operator can fix the schema.');
    }

    public function testDoesNotWarnForAToolWithValidDeclarations(): void
    {
        $logger = new ArrayLogger();
        $store = new PagedToolStore([[
            new Tool(name: 'echo', inputSchema: ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]]),
        ]]);

        (new ParameterHeaderValidationMiddleware($store, new Psr17Factory(), new Psr17Factory(), $logger))
            ->process(self::callPost(['region' => 'us-west1'], ['Mcp-Param-Region' => 'us-west1']), self::handler())
        ;

        self::assertSame([], $logger->recordsMatching(LogLevel::WARNING, 'Skipping {tool} header validation: its "x-mcp-header" declarations are invalid.'));
    }

    public function testScansEveryPageOfTheStore(): void
    {
        $handler = self::handler();
        $store = new PagedToolStore([
            [new Tool(name: 'first', inputSchema: ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'x-mcp-header' => 'A']]])],
            [new Tool(name: 'second', inputSchema: ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]])],
        ]);

        $response = (new ParameterHeaderValidationMiddleware($store, new Psr17Factory(), new Psr17Factory()))
            ->process(self::callPost(['region' => 'eu-west1'], ['Mcp-Param-Region' => 'us-east1'], tool: 'second'), $handler)
        ;

        self::assertFalse($handler->called, 'A binding declared on a later page must still be validated.');
        self::assertSame(400, $response->getStatusCode());
    }

    public function testScansTheStoreOnlyOnce(): void
    {
        $store = new PagedToolStore([[
            new Tool(name: 'echo', inputSchema: ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]]),
        ]]);
        $middleware = new ParameterHeaderValidationMiddleware($store, new Psr17Factory(), new Psr17Factory());

        $middleware->process(self::callPost(['region' => 'us-west1'], ['Mcp-Param-Region' => 'us-west1']), self::handler());
        $middleware->process(self::callPost(['region' => 'us-west1'], ['Mcp-Param-Region' => 'us-west1']), self::handler());

        self::assertSame(1, $store->listCalls, 'The binding scan is cached across requests.');
    }

    public function testAToolAddedAfterTheScanIsStillValidated(): void
    {
        $store = new ToolStore([
            'echo' => new ToolEntry(
                new Tool(name: 'echo', inputSchema: ['type' => 'object']),
                new ClosureToolExecutor(static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: [])),
            ),
        ]);
        $middleware = new ParameterHeaderValidationMiddleware($store, new Psr17Factory(), new Psr17Factory());

        $middleware->process(self::callPost([], tool: 'echo'), self::handler());

        $store->addTool(
            new Tool(name: 'later', inputSchema: ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]]),
            new ClosureToolExecutor(static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: [])),
        );

        $handler = self::handler();
        $response = $middleware->process(
            self::callPost(['region' => 'eu-west1'], ['Mcp-Param-Region' => 'us-east1'], tool: 'later'),
            $handler,
        );

        self::assertFalse($handler->called, 'A tool registered after the scan must not bypass validation.');
        self::assertSame(400, $response->getStatusCode());
    }

    public function testANonSeekableBodyStillReachesTheHandlerIntact(): void
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['region' => 'us-west1']],
        ], \JSON_THROW_ON_ERROR);
        $body = new NonSeekableStream($payload);
        $handler = self::handler();

        $response = self::middleware()->process(
            (new Psr17Factory())->createServerRequest('POST', 'https://mcp.test/')
                ->withBody($body)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Mcp-Param-Region', 'us-west1'),
            $handler,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->called);
        self::assertNotNull($handler->received);
        self::assertSame($payload, (string) $handler->received->getBody(), 'The downstream body is re-seated.');
        self::assertSame(1, $body->reads, 'The unrewindable body is read exactly once.');
    }

    public function testANonSeekableBodyIsStillValidatedAgainstItsHeaders(): void
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['region' => 'us-west1']],
        ], \JSON_THROW_ON_ERROR);
        $handler = self::handler();

        $response = self::middleware()->process(
            (new Psr17Factory())->createServerRequest('POST', 'https://mcp.test/')
                ->withBody(new NonSeekableStream($payload))
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Mcp-Param-Region', 'eu-west1'),
            $handler,
        );

        self::assertFalse($handler->called);
        self::assertSame(400, $response->getStatusCode());
    }

    private static function middleware(): ParameterHeaderValidationMiddleware
    {
        $factory = new Psr17Factory();
        $store = new PagedToolStore([[
            new Tool(name: 'echo', inputSchema: ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]]),
            new Tool(name: 'plain', inputSchema: ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]),
        ]]);

        return new ParameterHeaderValidationMiddleware($store, $factory, $factory);
    }

    private static function handler(): RecordingRequestHandler
    {
        return new RecordingRequestHandler((new Psr17Factory())->createResponse(200));
    }

    /**
     * @param array<string, mixed>  $arguments
     * @param array<string, string> $headers
     */
    private static function callPost(array $arguments, array $headers = [], mixed $id = 1, string $tool = 'echo'): ServerRequestInterface
    {
        return self::post([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], $headers);
    }

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string>       $headers
     */
    private static function post(array|string $body, array $headers = []): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://mcp.test/')
            ->withBody($factory->createStream(\is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR)))
        ;

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), associative: true);
        self::assertIsArray($decoded);

        return array_filter($decoded, is_string(...), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorPayload(ResponseInterface $response): array
    {
        $error = self::decode($response)['error'] ?? null;
        self::assertIsArray($error);

        return array_filter($error, is_string(...), \ARRAY_FILTER_USE_KEY);
    }
}
