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

namespace Nexus\Mcp\Tests\Server\Tool;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\AbstractPaginatedStore;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Server\Exception\ToolOutputValidationException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolStore::class)]
#[CoversClass(AbstractPaginatedStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ToolStoreTest extends TestCase
{
    public function testListReturnsRegisteredTools(): void
    {
        $store = new ToolStore(self::makeEntries('alpha', 'beta'));

        $result = $store->list(null);

        self::assertCount(2, $result->tools);
        self::assertSame('alpha', $result->tools[0]->name);
        self::assertSame('beta', $result->tools[1]->name);
        self::assertNull($result->nextCursor);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testListPreservesRegistrationOrderDeterministically(): void
    {
        // Names are intentionally not alphabetical so registration order is
        // distinguishable from a sorted order.
        $store = new ToolStore(self::makeEntries('zeta', 'alpha', 'mike', 'bravo'));

        $expected = ['zeta', 'alpha', 'mike', 'bravo'];

        $first = array_map(static fn(Tool $tool): string => $tool->name, $store->list(null)->tools);
        $second = array_map(static fn(Tool $tool): string => $tool->name, $store->list(null)->tools);

        self::assertSame($expected, $first);
        self::assertSame($expected, $second);
    }

    public function testListReflectsConfiguredTtlAndCacheScope(): void
    {
        $store = new ToolStore(self::makeEntries('alpha'), ttlMs: 120000, cacheScope: CacheScope::Public);

        $result = $store->list(null);

        self::assertSame(120000, $result->ttlMs);
        self::assertSame(CacheScope::Public, $result->cacheScope);
    }

    public function testListPaginatesWithCursor(): void
    {
        $store = new ToolStore(self::makeEntries('a', 'b', 'c'), pageSize: 2);

        $first = $store->list(null);
        self::assertCount(2, $first->tools);
        self::assertNotNull($first->nextCursor);
        self::assertSame('b', $first->nextCursor->cursor);

        $second = $store->list($first->nextCursor);
        self::assertCount(1, $second->tools);
        self::assertSame('c', $second->tools[0]->name);
        self::assertNull($second->nextCursor);
    }

    public function testListAfterLastItemReturnsEmptyPage(): void
    {
        $store = new ToolStore(self::makeEntries('only'), pageSize: 1);

        $page = $store->list(new Cursor(cursor: 'only'));

        self::assertSame([], $page->tools);
        self::assertNull($page->nextCursor);
    }

    public function testListWithEmptyStoreReturnsEmptyPage(): void
    {
        $page = new ToolStore()->list(null);

        self::assertSame([], $page->tools);
        self::assertNull($page->nextCursor);
    }

    public function testConstructorRejectsNonPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Tool store page size must be a positive integer, 0 given\.$/');

        new ToolStore([], 0);
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Tool store TTL must be a non-negative integer, -1 given.');

        new ToolStore(ttlMs: -1);
    }

    public function testConstructorRejectsIntegerEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Tool store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new ToolStore([1 => new ToolEntry(self::makeTool('one'), self::makeExecutor())]);
    }

    public function testConstructorRejectsEmptyStringEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Tool store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new ToolStore(['' => new ToolEntry(self::makeTool('one'), self::makeExecutor())]);
    }

    public function testListRejectsCursorThatMatchesNoEntry(): void
    {
        $store = new ToolStore(self::makeEntries('alpha'));

        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/^Cursor "missing" does not match any registered entry\.$/');

        $store->list(new Cursor(cursor: 'missing'));
    }

    public function testCallInvokesTheExecutorMatchingTheName(): void
    {
        $alphaResult = new CallToolResult(content: []);
        $betaResult = new CallToolResult(content: []);
        $captured = [];
        $store = new ToolStore([
            'alpha' => new ToolEntry(
                self::makeTool('alpha'),
                new ClosureToolExecutor(static function (?array $arguments, ServerContext $context) use ($alphaResult, &$captured): CallToolResult {
                    $captured[] = ['name' => 'alpha', 'arguments' => $arguments, 'requestId' => $context->requestId->id];

                    return $alphaResult;
                }),
            ),
            'beta' => new ToolEntry(
                self::makeTool('beta'),
                new ClosureToolExecutor(static function (?array $arguments, ServerContext $context) use ($betaResult, &$captured): CallToolResult {
                    $captured[] = ['name' => 'beta', 'arguments' => $arguments, 'requestId' => $context->requestId->id];

                    return $betaResult;
                }),
            ),
        ]);

        self::assertSame($betaResult, $store->call('beta', ['key' => 'value'], self::makeContext()));
        self::assertSame($alphaResult, $store->call('alpha', null, self::makeContext()));
        self::assertSame([
            ['name' => 'beta', 'arguments' => ['key' => 'value'], 'requestId' => 1],
            ['name' => 'alpha', 'arguments' => null, 'requestId' => 1],
        ], $captured);
    }

    public function testCallThrowsForUnknownToolName(): void
    {
        $store = new ToolStore();

        $this->expectException(ToolNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No tool registered under name "missing"\.$/');

        $store->call('missing', null, self::makeContext());
    }

    public function testCallThrowsInvalidParamsWhenArgumentsViolateInputSchema(): void
    {
        $store = new ToolStore([
            'search' => new ToolEntry(
                new Tool(name: 'search', inputSchema: [
                    'type' => 'object',
                    'properties' => ['q' => ['type' => 'string']],
                    'required' => ['q'],
                ]),
                self::makeExecutor(),
            ),
        ]);

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageMatches('/^Invalid arguments for tool "search": /');

        $store->call('search', ['q' => 123], self::makeContext());
    }

    public function testCallAcceptsArgumentsSatisfyingRequiredInputSchema(): void
    {
        $result = new CallToolResult(content: []);
        $store = new ToolStore([
            'search' => new ToolEntry(
                new Tool(name: 'search', inputSchema: [
                    'type' => 'object',
                    'properties' => ['q' => ['type' => 'string']],
                    'required' => ['q'],
                ]),
                self::makeExecutorReturning($result),
            ),
        ]);

        self::assertSame($result, $store->call('search', ['q' => 'hello'], self::makeContext()));
    }

    public function testCallAcceptsEmptyArrayArgumentsAsEmptyObject(): void
    {
        $result = new CallToolResult(content: []);
        $store = new ToolStore([
            'noop' => new ToolEntry(self::makeTool('noop'), self::makeExecutorReturning($result)),
        ]);

        self::assertSame($result, $store->call('noop', [], self::makeContext()));
    }

    public function testCallReturnsResultWhenStructuredContentConformsToOutputSchema(): void
    {
        $result = new CallToolResult(content: [], structuredContent: ['n' => 42]);
        $store = new ToolStore([
            'report' => new ToolEntry(self::makeToolWithOutputSchema('report'), self::makeExecutorReturning($result)),
        ]);

        self::assertSame($result, $store->call('report', null, self::makeContext()));
    }

    public function testCallThrowsToolOutputValidationWhenStructuredContentViolatesOutputSchema(): void
    {
        $store = new ToolStore([
            'report' => new ToolEntry(
                self::makeToolWithOutputSchema('report'),
                self::makeExecutorReturning(new CallToolResult(content: [], structuredContent: ['n' => 'oops'])),
            ),
        ]);

        $this->expectException(ToolOutputValidationException::class);
        $this->expectExceptionMessageMatches('/^Tool "report" returned structuredContent that does not conform to its outputSchema: /');

        $store->call('report', null, self::makeContext());
    }

    public function testCallSkipsOutputValidationWhenResultHasNoStructuredContent(): void
    {
        $result = new CallToolResult(content: [new TextContent(text: 'hi')]);
        $store = new ToolStore([
            'report' => new ToolEntry(self::makeToolWithOutputSchema('report'), self::makeExecutorReturning($result)),
        ]);

        self::assertSame($result, $store->call('report', null, self::makeContext()));
    }

    public function testCallSkipsOutputValidationForErrorResults(): void
    {
        $result = new CallToolResult(content: [new TextContent(text: 'boom')], structuredContent: ['n' => 'oops'], isError: true);
        $store = new ToolStore([
            'report' => new ToolEntry(self::makeToolWithOutputSchema('report'), self::makeExecutorReturning($result)),
        ]);

        self::assertSame($result, $store->call('report', null, self::makeContext()));
    }

    public function testCallAcceptsEmptyStructuredContentAsEmptyObject(): void
    {
        $result = new CallToolResult(content: [], structuredContent: []);
        $store = new ToolStore([
            'report' => new ToolEntry(
                new Tool(name: 'report', inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object']),
                self::makeExecutorReturning($result),
            ),
        ]);

        self::assertSame($result, $store->call('report', null, self::makeContext()));
    }

    public function testCallSkipsOutputValidationForAnInputRequiredResult(): void
    {
        // The output schema demands an `n` property, which a result still awaiting input cannot carry.
        $asked = new InputRequiredResult(requestState: 'state-1');
        $store = new ToolStore([
            'report' => new ToolEntry(
                self::makeToolWithOutputSchema('report'),
                new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): InputRequiredResult => $asked),
            ),
        ]);

        self::assertSame($asked, $store->call('report', null, self::makeContext()));
    }

    private static function makeTool(string $name): Tool
    {
        return new Tool(name: $name, inputSchema: ['type' => 'object']);
    }

    private static function makeToolWithOutputSchema(string $name): Tool
    {
        return new Tool(name: $name, inputSchema: ['type' => 'object'], outputSchema: [
            'type' => 'object',
            'properties' => ['n' => ['type' => 'integer']],
            'required' => ['n'],
        ]);
    }

    private static function makeExecutorReturning(CallToolResult $result): ClosureToolExecutor
    {
        return new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): CallToolResult => $result);
    }

    /**
     * @return array<non-empty-string, ToolEntry>
     */
    private static function makeEntries(string ...$names): array
    {
        $entries = [];

        foreach ($names as $name) {
            \assert('' !== $name);
            $entries[$name] = new ToolEntry(self::makeTool($name), self::makeExecutor());
        }

        return $entries;
    }

    private static function makeExecutor(): ClosureToolExecutor
    {
        return new ClosureToolExecutor(
            static fn(?array $arguments, ServerContext $context): CallToolResult => new CallToolResult(content: []),
        );
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
