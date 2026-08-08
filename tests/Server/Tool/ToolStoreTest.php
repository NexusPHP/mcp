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
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Server\Exception\ToolOutputValidationException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ToolStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ToolStoreTest extends AbstractMcpTestCase
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
        $store = new ToolStore(self::makeEntries('alpha'), ttlMs: 120_000, cacheScope: CacheScope::Public);

        $result = $store->list(null);

        self::assertSame(120_000, $result->ttlMs);
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

    public function testConstructorRejectsAnEntryKeyThatDoesNotMatchItsName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Tool store entry key "\'mismatch\'" must match its tool name "\'one\'".');

        new ToolStore(['mismatch' => new ToolEntry(self::makeTool('one'), self::makeExecutor())]);
    }

    public function testAnAllDigitNameIsServedDespiteBecomingAnIntegerKey(): void
    {
        // The name rules permit all digits, and PHP turns such a key into an int. Pagination must still
        // mint a cursor that names the entry rather than its position.
        $store = new ToolStore(['123' => new ToolEntry(self::makeTool('123'), self::makeExecutor()), 'beta' => new ToolEntry(self::makeTool('beta'), self::makeExecutor())], pageSize: 1);

        $first = $store->list(null);
        self::assertNotNull($first->nextCursor);
        self::assertSame('123', $first->nextCursor->cursor);

        $second = $store->list($first->nextCursor);
        self::assertSame(
            ['beta'],
            array_map(static fn(Tool $e): string => $e->name, $second->tools),
        );
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

    #[DataProvider('provideCallAcceptsAnyValueForAnAlwaysValidPropertyCases')]
    public function testCallAcceptsAnyValueForAnAlwaysValidProperty(mixed $payload): void
    {
        $result = new CallToolResult(content: []);
        $store = new ToolStore([
            'anything' => new ToolEntry(
                new Tool(name: 'anything', inputSchema: [
                    'type' => 'object',
                    'properties' => ['payload' => true],
                    'required' => ['payload'],
                ]),
                self::makeExecutorReturning($result),
            ),
        ]);

        self::assertSame($result, $store->call('anything', ['payload' => $payload], self::makeContext()));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function provideCallAcceptsAnyValueForAnAlwaysValidPropertyCases(): iterable
    {
        yield 'string' => ['hi'];

        yield 'int' => [42];

        yield 'float' => [1.5];

        yield 'bool' => [true];

        yield 'null' => [null];

        yield 'list' => [['a']];

        yield 'map' => [['k' => 'v']];
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

    public function testCallAcceptsEmptyStructuredContentAsAnEmptyArray(): void
    {
        $result = new CallToolResult(content: [], structuredContent: []);
        $store = new ToolStore([
            'report' => new ToolEntry(
                new Tool(name: 'report', inputSchema: ['type' => 'object'], outputSchema: ['type' => 'array']),
                self::makeExecutorReturning($result),
            ),
        ]);

        self::assertSame($result, $store->call('report', null, self::makeContext()));
    }

    public function testCallReadsEmptyStructuredContentAsAnObjectForATypeUnionWithoutArray(): void
    {
        $result = new CallToolResult(content: [], structuredContent: []);
        $store = new ToolStore([
            'report' => new ToolEntry(
                new Tool(name: 'report', inputSchema: ['type' => 'object'], outputSchema: ['type' => ['object', 'null']]),
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

    public function testAddToolRegistersItAndAnnouncesTheChange(): void
    {
        $store = new ToolStore(self::makeEntries('alpha'));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        $store->addTool(self::makeTool('beta'), self::makeExecutor());

        self::assertSame(
            ['alpha', 'beta'],
            array_map(static fn(Tool $tool): string => $tool->name, $store->list(null)->tools),
        );
        self::assertSame(1, $changes);
    }

    public function testAddToolReplacesAToolOfTheSameName(): void
    {
        $store = new ToolStore(self::makeEntries('alpha'));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        $store->addTool(new Tool(name: 'alpha', title: 'Renamed', inputSchema: ['type' => 'object']), self::makeExecutor());

        $tools = $store->list(null)->tools;
        self::assertCount(1, $tools);
        self::assertSame('Renamed', $tools[0]->title);
        self::assertSame(1, $changes);
    }

    public function testRemoveToolDropsItAndAnnouncesTheChange(): void
    {
        $store = new ToolStore(self::makeEntries('alpha', 'beta'));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        self::assertTrue($store->removeTool('alpha'));
        self::assertSame(
            ['beta'],
            array_map(static fn(Tool $tool): string => $tool->name, $store->list(null)->tools),
        );
        self::assertSame(1, $changes);
    }

    public function testRemoveToolIsSilentWhenNoToolMatches(): void
    {
        $store = new ToolStore(self::makeEntries('alpha'));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        self::assertFalse($store->removeTool('missing'));
        self::assertCount(1, $store->list(null)->tools);
        self::assertSame(0, $changes);
    }

    public function testEveryRegisteredListenerHearsAChange(): void
    {
        $store = new ToolStore();
        $heard = [];
        $store->onListChanged(static function () use (&$heard): void { $heard[] = 'first'; });
        $store->onListChanged(static function () use (&$heard): void { $heard[] = 'second'; });

        $store->addTool(self::makeTool('alpha'), self::makeExecutor());

        self::assertSame(['first', 'second'], $heard);
    }

    public function testAnAddedToolIsCallable(): void
    {
        $store = new ToolStore();
        $store->addTool(self::makeTool('alpha'), self::makeExecutorReturning(new CallToolResult(content: [])));

        $result = $store->call('alpha', null, self::makeContext());

        if (! $result instanceof CallToolResult) {
            self::fail('Expected a tool result.');
        }

        self::assertSame([], $result->content);
    }

    public function testConstructorRefusesAnUnconventionalName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('tool "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.');

        new ToolStore(['Project Files' => new ToolEntry(new Tool(name: 'Project Files', inputSchema: ['type' => 'object']), new ClosureToolExecutor(static fn(?array $a, ServerContext $c): CallToolResult => new CallToolResult(content: [])))]);
    }

    public function testAddRefusesAnUnconventionalName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('tool "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.');

        (new ToolStore())->addTool(new Tool(name: 'Project Files', inputSchema: ['type' => 'object']), new ClosureToolExecutor(static fn(?array $a, ServerContext $c): CallToolResult => new CallToolResult(content: [])));
    }

    public function testCallValidatesAnArgumentNameThatIsAllDigits(): void
    {
        $seen = null;
        $store = new ToolStore();
        $store->addTool(
            Tool::fromArray([
                'name' => 'digit',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['0' => ['type' => 'string'], 'a' => ['type' => 'string']],
                ],
            ]),
            new ClosureToolExecutor(static function (?array $arguments) use (&$seen): CallToolResult {
                $seen = $arguments;

                return new CallToolResult(content: [new TextContent(text: 'ok')]);
            }),
        );

        $store->call('digit', ['0' => 'v'], self::makeContext());

        self::assertSame([0 => 'v'], $seen);
    }

    public function testCallValidatesAgainstASchemaWhosePropertyNamesAreAllDigits(): void
    {
        $store = new ToolStore();
        $store->addTool(
            Tool::fromArray([
                'name' => 'digit-only',
                'inputSchema' => ['type' => 'object', 'properties' => ['0' => ['type' => 'string']]],
            ]),
            new ClosureToolExecutor(static fn(): CallToolResult => new CallToolResult(content: [new TextContent(text: 'ok')])),
        );

        $result = $store->call('digit-only', ['a' => 'b'], self::makeContext());

        self::assertInstanceOf(CallToolResult::class, $result);
    }

    public function testCallRejectsAnArgumentThatViolatesADigitNamedProperty(): void
    {
        $store = new ToolStore();
        $store->addTool(
            Tool::fromArray([
                'name' => 'digit',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['0' => ['type' => 'string']],
                    'required' => ['0'],
                ],
            ]),
            new ClosureToolExecutor(static fn(): CallToolResult => new CallToolResult(content: [new TextContent(text: 'ok')])),
        );

        $this->expectException(InvalidParamsException::class);

        $store->call('digit', ['a' => 'b'], self::makeContext());
    }

    /**
     * @param non-empty-string $name
     */
    private static function makeTool(string $name): Tool
    {
        return new Tool(name: $name, inputSchema: ['type' => 'object']);
    }

    /**
     * @param non-empty-string $name
     */
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
