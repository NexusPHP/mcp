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

namespace Nexus\Mcp\Tests\Server\Handler\Request;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\CallToolRequestParams;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\ResultMetaObject;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Server\Handler\Request\CallToolRequestHandler;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * @internal
 */
#[CoversClass(CallToolRequestHandler::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CallToolRequestHandlerTest extends TestCase
{
    public function testForwardsNameArgumentsAndContextToStore(): void
    {
        $captured = ['arguments' => null, 'requestId' => 0];
        $store = new ToolStore([
            'echo' => new ToolEntry(
                new Tool(name: 'echo', inputSchema: ['type' => 'object']),
                new ClosureToolExecutor(static function (?array $arguments, ServerContext $context) use (&$captured): CallToolResult {
                    $captured = ['arguments' => $arguments, 'requestId' => $context->requestId->id];

                    return new CallToolResult(content: []);
                }),
            ),
        ]);
        $handler = new CallToolRequestHandler($store);

        $handler->handle(
            new CallToolRequest(id: new RequestId(id: 42), params: new CallToolRequestParams(name: 'echo', meta: RequestMetaObjectFactory::create(), arguments: ['x' => 1])),
            self::makeContext(),
        );

        self::assertSame(['arguments' => ['x' => 1], 'requestId' => 99], $captured);
    }

    public function testPassesAnInputRequiredResultStraightThrough(): void
    {
        $asked = new InputRequiredResult(requestState: 'state-1');
        $store = new ToolStore([
            'ask' => new ToolEntry(
                new Tool(name: 'ask', inputSchema: ['type' => 'object']),
                new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): InputRequiredResult => $asked),
            ),
        ]);

        $result = new CallToolRequestHandler($store)->handle(
            new CallToolRequest(id: new RequestId(id: 42), params: new CallToolRequestParams(name: 'ask', meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        self::assertSame($asked, $result, 'A tool awaiting input has no content to back-fill.');
    }

    public function testReturnsResultFromStoreUnchanged(): void
    {
        $expected = new CallToolResult(content: []);
        $store = new ToolStore([
            'echo' => new ToolEntry(
                new Tool(name: 'echo', inputSchema: ['type' => 'object']),
                new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): CallToolResult => $expected),
            ),
        ]);
        $handler = new CallToolRequestHandler($store);

        $result = $handler->handle(
            new CallToolRequest(id: new RequestId(id: 1), params: new CallToolRequestParams(name: 'echo', meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        if (! $result instanceof CallToolResult) {
            self::fail('Expected a CallToolResult.');
        }

        self::assertSame($expected, $result);
    }

    public function testMirrorsStructuredContentIntoTextBlockWhenContentEmpty(): void
    {
        $structured = ['path' => 'docs/intro', 'lines' => 42];
        $store = new ToolStore([
            'report' => new ToolEntry(
                new Tool(name: 'report', inputSchema: ['type' => 'object']),
                new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): CallToolResult => new CallToolResult(
                    content: [],
                    structuredContent: $structured,
                    isError: false,
                    meta: new ResultMetaObject(extras: ['vendor' => 'x']),
                )),
            ),
        ]);
        $handler = new CallToolRequestHandler($store);

        $result = $handler->handle(
            new CallToolRequest(id: new RequestId(id: 1), params: new CallToolRequestParams(name: 'report', meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        if (! $result instanceof CallToolResult) {
            self::fail('Expected a CallToolResult.');
        }

        self::assertCount(1, $result->content);
        $block = $result->content[0];

        if (! $block instanceof TextContent) {
            self::fail('Mirrored content must be TextContent.');
        }

        self::assertSame('{"path":"docs/intro","lines":42}', $block->text);
        self::assertSame($structured, $result->structuredContent);
        self::assertFalse($result->isError);
        self::assertSame(['vendor' => 'x'], $result->meta->toArray());
    }

    public function testDoesNotMirrorWhenContentAlreadyPresent(): void
    {
        $expected = new CallToolResult(
            content: [new TextContent(text: 'explicit')],
            structuredContent: ['lines' => 42],
        );
        $store = new ToolStore([
            'report' => new ToolEntry(
                new Tool(name: 'report', inputSchema: ['type' => 'object']),
                new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): CallToolResult => $expected),
            ),
        ]);
        $handler = new CallToolRequestHandler($store);

        $result = $handler->handle(
            new CallToolRequest(id: new RequestId(id: 1), params: new CallToolRequestParams(name: 'report', meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        if (! $result instanceof CallToolResult) {
            self::fail('Expected a CallToolResult.');
        }

        self::assertSame($expected, $result);
    }

    public function testWrapsUnserialisableStructuredContentIntoGenericError(): void
    {
        $store = new ToolStore([
            'report' => new ToolEntry(
                new Tool(name: 'report', inputSchema: ['type' => 'object']),
                new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): CallToolResult => new CallToolResult(
                    content: [],
                    structuredContent: ['value' => \NAN],
                )),
            ),
        ]);
        $logger = new ArrayLogger();
        $handler = new CallToolRequestHandler($store, $logger);

        $result = $handler->handle(
            new CallToolRequest(id: new RequestId(id: 1), params: new CallToolRequestParams(name: 'report', meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        if (! $result instanceof CallToolResult) {
            self::fail('Expected a CallToolResult.');
        }

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        $block = $result->content[0];

        if (! $block instanceof TextContent) {
            self::fail('Wrapped error content must be TextContent.');
        }

        self::assertSame('Tool execution failed.', $block->text);
        self::assertCount(
            1,
            $logger->recordsMatching(
                LogLevel::ERROR,
                'Uncaught tool executor exception. Returning generic error to peer.',
            ),
        );
    }

    public function testInvalidArgumentsPropagateAsInvalidParamsException(): void
    {
        $store = new ToolStore([
            'search' => new ToolEntry(
                new Tool(name: 'search', inputSchema: [
                    'type' => 'object',
                    'properties' => ['q' => ['type' => 'string']],
                    'required' => ['q'],
                ]),
                new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): CallToolResult => new CallToolResult(content: [])),
            ),
        ]);
        $handler = new CallToolRequestHandler($store);

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageMatches('/^Invalid arguments for tool "search": /');

        $handler->handle(
            new CallToolRequest(id: new RequestId(id: 1), params: new CallToolRequestParams(name: 'search', meta: RequestMetaObjectFactory::create(), arguments: ['q' => 123])),
            self::makeContext(),
        );
    }

    public function testOutputSchemaViolationYieldsGenericErrorResultAndIsLogged(): void
    {
        $store = new ToolStore([
            'report' => new ToolEntry(
                new Tool(name: 'report', inputSchema: ['type' => 'object'], outputSchema: [
                    'type' => 'object',
                    'properties' => ['n' => ['type' => 'integer']],
                    'required' => ['n'],
                ]),
                new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): CallToolResult => new CallToolResult(content: [], structuredContent: ['n' => 'oops'])),
            ),
        ]);
        $logger = new ArrayLogger();
        $handler = new CallToolRequestHandler($store, $logger);

        $result = $handler->handle(
            new CallToolRequest(id: new RequestId(id: 1), params: new CallToolRequestParams(name: 'report', meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        if (! $result instanceof CallToolResult) {
            self::fail('Expected a CallToolResult.');
        }

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        $block = $result->content[0];

        if (! $block instanceof TextContent) {
            self::fail('Wrapped error content must be TextContent.');
        }

        self::assertSame('Tool execution failed.', $block->text);

        $matches = $logger->recordsMatching(
            LogLevel::ERROR,
            'Tool returned structuredContent that does not conform to its outputSchema.',
        );
        self::assertCount(1, $matches);
        self::assertSame('report', $matches[0]['context']['tool'] ?? null);
    }

    public function testPropagatesToolNotFoundFromStore(): void
    {
        $handler = new CallToolRequestHandler(new ToolStore());

        $this->expectException(ToolNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No tool registered under name "missing"\.$/');

        $handler->handle(
            new CallToolRequest(id: new RequestId(id: 1), params: new CallToolRequestParams(name: 'missing', meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );
    }

    public function testRuntimeExecutorFailureIsWrappedIntoGenericErrorResultAndLoggedServerSide(): void
    {
        $exception = new \RuntimeException('db timeout at /opt/app/vendor/foo/bar/Loader.php:217');
        $store = new ToolStore([
            'flaky' => new ToolEntry(
                new Tool(name: 'flaky', inputSchema: ['type' => 'object']),
                new ClosureToolExecutor(static function () use ($exception): CallToolResult {
                    throw $exception;
                }),
            ),
        ]);
        $logger = new ArrayLogger();
        $handler = new CallToolRequestHandler($store, $logger);

        $result = $handler->handle(
            new CallToolRequest(id: new RequestId(id: 1), params: new CallToolRequestParams(name: 'flaky', meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        if (! $result instanceof CallToolResult) {
            self::fail('Expected a CallToolResult.');
        }

        self::assertTrue($result->isError);
        self::assertNull($result->structuredContent);
        self::assertCount(1, $result->content);
        $block = $result->content[0];
        self::assertInstanceOf(TextContent::class, $block);
        self::assertSame(
            'Tool execution failed.',
            $block->text,
            'Peer-facing message must not echo raw throwable text that may carry paths, vendor structure, or secrets.',
        );

        $matches = $logger->recordsMatching(
            LogLevel::ERROR,
            'Uncaught tool executor exception. Returning generic error to peer.',
        );
        self::assertCount(1, $matches);
        self::assertSame('flaky', $matches[0]['context']['tool'] ?? null);
        self::assertSame($exception, $matches[0]['context']['exception'] ?? null);
    }

    public function testNonRuntimeExceptionFromExecutorAlsoYieldsGenericErrorAndIsLogged(): void
    {
        $exception = new \LogicException('bad branch');
        $store = new ToolStore([
            'flaky' => new ToolEntry(
                new Tool(name: 'flaky', inputSchema: ['type' => 'object']),
                new ClosureToolExecutor(static function () use ($exception): CallToolResult {
                    throw $exception;
                }),
            ),
        ]);
        $logger = new ArrayLogger();
        $handler = new CallToolRequestHandler($store, $logger);

        $result = $handler->handle(
            new CallToolRequest(id: new RequestId(id: 1), params: new CallToolRequestParams(name: 'flaky', meta: RequestMetaObjectFactory::create())),
            self::makeContext(),
        );

        if (! $result instanceof CallToolResult) {
            self::fail('Expected a CallToolResult.');
        }

        self::assertTrue($result->isError);
        self::assertCount(1, $result->content);
        $block = $result->content[0];

        if (! $block instanceof TextContent) {
            self::fail('Wrapped error content must be TextContent.');
        }

        self::assertSame('Tool execution failed.', $block->text);

        $matches = $logger->recordsMatching(
            LogLevel::ERROR,
            'Uncaught tool executor exception. Returning generic error to peer.',
        );
        self::assertCount(1, $matches);
        self::assertSame($exception, $matches[0]['context']['exception'] ?? null);
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 99),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
