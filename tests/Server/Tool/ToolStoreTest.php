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
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Icon;
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
        $store = new ToolStore($this->makeEntries('alpha', 'beta'));

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
        $store = new ToolStore($this->makeEntries('zeta', 'alpha', 'mike', 'bravo'));

        $expected = ['zeta', 'alpha', 'mike', 'bravo'];

        $first = array_map(static fn(Tool $tool): string => $tool->name, $store->list(null)->tools);
        $second = array_map(static fn(Tool $tool): string => $tool->name, $store->list(null)->tools);

        self::assertSame($expected, $first);
        self::assertSame($expected, $second);
    }

    public function testListReflectsConfiguredTtlAndCacheScope(): void
    {
        $store = new ToolStore($this->makeEntries('alpha'), ttlMs: 120_000, cacheScope: CacheScope::Public);

        $result = $store->list(null);

        self::assertSame(120_000, $result->ttlMs);
        self::assertSame(CacheScope::Public, $result->cacheScope);
    }

    public function testListPaginatesWithCursor(): void
    {
        $store = new ToolStore($this->makeEntries('a', 'b', 'c'), pageSize: 2);

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

        new ToolStore(['mismatch' => new ToolEntry($this->makeTool('one'), $this->makeExecutor())]);
    }

    public function testAnAllDigitNameIsServedDespiteBecomingAnIntegerKey(): void
    {
        $store = new ToolStore(['123' => new ToolEntry($this->makeTool('123'), $this->makeExecutor()), 'beta' => new ToolEntry($this->makeTool('beta'), $this->makeExecutor())], pageSize: 1);

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
                $this->makeTool('alpha'),
                new ClosureToolExecutor(static function (?array $arguments, ServerContext $context) use ($alphaResult, &$captured): CallToolResult {
                    $captured[] = ['name' => 'alpha', 'arguments' => $arguments, 'requestId' => $context->requestId->id];

                    return $alphaResult;
                }),
            ),
            'beta' => new ToolEntry(
                $this->makeTool('beta'),
                new ClosureToolExecutor(static function (?array $arguments, ServerContext $context) use ($betaResult, &$captured): CallToolResult {
                    $captured[] = ['name' => 'beta', 'arguments' => $arguments, 'requestId' => $context->requestId->id];

                    return $betaResult;
                }),
            ),
        ]);

        self::assertSame($betaResult, $store->call('beta', ['key' => 'value'], $this->makeContext()));
        self::assertSame($alphaResult, $store->call('alpha', null, $this->makeContext()));
        self::assertSame([
            ['name' => 'beta', 'arguments' => ['key' => 'value'], 'requestId' => 1],
            ['name' => 'alpha', 'arguments' => null, 'requestId' => 1],
        ], $captured);
    }

    public function testCallThrowsForUnknownToolName(): void
    {
        $store = new ToolStore();

        $this->expectException(ToolNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No tool registered under name "missing"\. The server registers tools with addTool\(\) or register\(\)\.$/');

        $store->call('missing', null, $this->makeContext());
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
                $this->makeExecutor(),
            ),
        ]);

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('Invalid arguments for tool "search": "q" must be a string, int given.');

        $store->call('search', ['q' => 123], $this->makeContext());
    }

    public function testCallListsEachArgumentViolationWithItsPointer(): void
    {
        $store = new ToolStore([
            'search' => new ToolEntry(
                new Tool(name: 'search', inputSchema: [
                    'type' => 'object',
                    'properties' => ['q' => ['type' => 'string'], 'limit' => ['type' => 'integer']],
                    'required' => ['q'],
                ]),
                $this->makeExecutor(),
            ),
        ]);

        try {
            $store->call('search', ['q' => 123, 'limit' => 'ten'], $this->makeContext());
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertSame(
                ['validation_errors' => [
                    ['pointer' => '/q', 'message' => '"q" must be a string, int given.'],
                    ['pointer' => '/limit', 'message' => '"limit" must be an integer, string given.'],
                ]],
                $e->errorData,
            );
        }
    }

    public function testCallReportsAtMostEightViolations(): void
    {
        $store = $this->buildClosedSchemaStore();
        $arguments = [];

        for ($i = 0; $i < 12; ++$i) {
            $arguments['undeclared'.$i] = $i;
        }

        try {
            $store->call('search', $arguments, $this->makeContext());
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertIsArray($e->errorData);
            self::assertArrayHasKey('validation_errors', $e->errorData);
            self::assertIsArray($e->errorData['validation_errors']);
            self::assertCount(8, $e->errorData['validation_errors']);
            self::assertArrayHasKey(0, $e->errorData['validation_errors']);
            self::assertSame(
                ['pointer' => '', 'message' => 'carries the undeclared "undeclared0" key.'],
                $e->errorData['validation_errors'][0],
            );
        }
    }

    public function testCallSanitisesAPeerPropertyNameInTheViolationList(): void
    {
        $store = $this->buildClosedSchemaStore();

        try {
            $store->call('search', ["ev\x1b[2K\x07il" => 1, str_repeat('A', 300) => 2], $this->makeContext());
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertIsArray($e->errorData);
            self::assertArrayHasKey('validation_errors', $e->errorData);
            self::assertIsArray($e->errorData['validation_errors']);
            self::assertArrayHasKey(0, $e->errorData['validation_errors']);
            self::assertArrayHasKey(1, $e->errorData['validation_errors']);
            [$escaped, $bounded] = $e->errorData['validation_errors'];
            self::assertSame(['pointer' => '', 'message' => 'carries the undeclared "ev\x1b[2K\x07il" key.'], $escaped);
            self::assertIsArray($bounded);
            self::assertArrayHasKey('message', $bounded);
            self::assertIsString($bounded['message']);
            self::assertSame(256, \strlen($bounded['message']));
            self::assertStringEndsWith('...', $bounded['message']);
        }
    }

    public function testCallBoundsAPeerPropertyNameQuotedByTheValidator(): void
    {
        $store = $this->buildClosedSchemaStore();

        try {
            $store->call('search', [str_repeat('A', 200_000) => 1], $this->makeContext());
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertSame(256, \strlen($e->getMessage()));
            self::assertStringEndsWith('...', $e->getMessage());
        }
    }

    public function testCallEscapesControlBytesInAPeerPropertyName(): void
    {
        $store = $this->buildClosedSchemaStore();

        try {
            $store->call('search', ["ev\x1b[2K\x07il" => 1], $this->makeContext());
            self::fail('Expected InvalidParamsException.');
        } catch (InvalidParamsException $e) {
            self::assertStringContainsString('ev\\x1b[2K\\x07il', $e->getMessage());
            self::assertDoesNotMatchRegularExpression('/[^\x20-\x7E]/', $e->getMessage());
        }
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
                $this->makeExecutorReturning($result),
            ),
        ]);

        self::assertSame($result, $store->call('search', ['q' => 'hello'], $this->makeContext()));
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
                $this->makeExecutorReturning($result),
            ),
        ]);

        self::assertSame($result, $store->call('anything', ['payload' => $payload], $this->makeContext()));
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

    public function testCallSurvivesAnEmptySubSchemaInAHandWrittenInputSchema(): void
    {
        $result = new CallToolResult(content: []);
        $store = new ToolStore([
            'loose' => new ToolEntry(
                new Tool(name: 'loose', inputSchema: [
                    'type' => 'object',
                    'properties' => ['extra' => []],
                ]),
                $this->makeExecutorReturning($result),
            ),
        ]);

        self::assertSame($result, $store->call('loose', ['extra' => 'anything'], $this->makeContext()));
    }

    public function testCallAcceptsEmptyArrayArgumentsAsEmptyObject(): void
    {
        $result = new CallToolResult(content: []);
        $store = new ToolStore([
            'noop' => new ToolEntry($this->makeTool('noop'), $this->makeExecutorReturning($result)),
        ]);

        self::assertSame($result, $store->call('noop', [], $this->makeContext()));
    }

    public function testCallReturnsResultWhenStructuredContentConformsToOutputSchema(): void
    {
        $result = new CallToolResult(content: [], structuredContent: ['n' => 42]);
        $store = new ToolStore([
            'report' => new ToolEntry($this->makeToolWithOutputSchema('report'), $this->makeExecutorReturning($result)),
        ]);

        self::assertSame($result, $store->call('report', null, $this->makeContext()));
    }

    public function testCallThrowsToolOutputValidationWhenStructuredContentViolatesOutputSchema(): void
    {
        $store = new ToolStore([
            'report' => new ToolEntry(
                $this->makeToolWithOutputSchema('report'),
                $this->makeExecutorReturning(new CallToolResult(content: [], structuredContent: ['n' => 'oops'])),
            ),
        ]);

        $this->expectException(ToolOutputValidationException::class);
        $this->expectExceptionMessageMatches('/^Tool "report" returned structuredContent that does not conform to its outputSchema: /');

        $store->call('report', null, $this->makeContext());
    }

    public function testCallRejectsAResultCarryingNoStructuredContentWhenOutputSchemaIsDeclared(): void
    {
        $store = new ToolStore([
            'report' => new ToolEntry(
                $this->makeToolWithOutputSchema('report'),
                $this->makeExecutorReturning(new CallToolResult(content: [new TextContent(text: 'hi')])),
            ),
        ]);

        $this->expectException(ToolOutputValidationException::class);
        $this->expectExceptionMessageIs('Tool "report" declares an outputSchema but its result carries no structuredContent.');

        $store->call('report', null, $this->makeContext());
    }

    public function testCallSkipsOutputValidationForErrorResults(): void
    {
        $result = new CallToolResult(content: [new TextContent(text: 'boom')], structuredContent: ['n' => 'oops'], isError: true);
        $store = new ToolStore([
            'report' => new ToolEntry($this->makeToolWithOutputSchema('report'), $this->makeExecutorReturning($result)),
        ]);

        self::assertSame($result, $store->call('report', null, $this->makeContext()));
    }

    public function testCallAcceptsEmptyStructuredContentAsEmptyObject(): void
    {
        $result = new CallToolResult(content: [], structuredContent: []);
        $store = new ToolStore([
            'report' => new ToolEntry(
                new Tool(name: 'report', inputSchema: ['type' => 'object'], outputSchema: ['type' => 'object']),
                $this->makeExecutorReturning($result),
            ),
        ]);

        self::assertSame($result, $store->call('report', null, $this->makeContext()));
    }

    public function testCallAcceptsEmptyStructuredContentAsAnEmptyArray(): void
    {
        $result = new CallToolResult(content: [], structuredContent: []);
        $store = new ToolStore([
            'report' => new ToolEntry(
                new Tool(name: 'report', inputSchema: ['type' => 'object'], outputSchema: ['type' => 'array']),
                $this->makeExecutorReturning($result),
            ),
        ]);

        self::assertSame($result, $store->call('report', null, $this->makeContext()));
    }

    public function testCallReadsEmptyStructuredContentAsAnObjectForATypeUnionWithoutArray(): void
    {
        $result = new CallToolResult(content: [], structuredContent: []);
        $store = new ToolStore([
            'report' => new ToolEntry(
                new Tool(name: 'report', inputSchema: ['type' => 'object'], outputSchema: ['type' => ['object', 'null']]),
                $this->makeExecutorReturning($result),
            ),
        ]);

        self::assertSame($result, $store->call('report', null, $this->makeContext()));
    }

    public function testCallSkipsOutputValidationForAnInputRequiredResult(): void
    {
        $asked = new InputRequiredResult(requestState: 'state-1');
        $store = new ToolStore([
            'report' => new ToolEntry(
                $this->makeToolWithOutputSchema('report'),
                new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): InputRequiredResult => $asked),
            ),
        ]);

        self::assertSame($asked, $store->call('report', null, $this->makeContext()));
    }

    public function testAddToolRegistersItAndAnnouncesTheChange(): void
    {
        $store = new ToolStore($this->makeEntries('alpha'));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        $store->addTool($this->makeTool('beta'), $this->makeExecutor());

        self::assertSame(
            ['alpha', 'beta'],
            array_map(static fn(Tool $tool): string => $tool->name, $store->list(null)->tools),
        );
        self::assertSame(1, $changes);
    }

    public function testAddToolReplacesAToolOfTheSameName(): void
    {
        $store = new ToolStore($this->makeEntries('alpha'));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        $store->addTool(new Tool(name: 'alpha', title: 'Renamed', inputSchema: ['type' => 'object']), $this->makeExecutor());

        $tools = $store->list(null)->tools;
        self::assertCount(1, $tools);
        self::assertSame('Renamed', $tools[0]->title);
        self::assertSame(1, $changes);
    }

    public function testRemoveToolDropsItAndAnnouncesTheChange(): void
    {
        $store = new ToolStore($this->makeEntries('alpha', 'beta'));
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
        $store = new ToolStore($this->makeEntries('alpha'));
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

        $store->addTool($this->makeTool('alpha'), $this->makeExecutor());

        self::assertSame(['first', 'second'], $heard);
    }

    public function testAnAddedToolIsCallable(): void
    {
        $store = new ToolStore();
        $store->addTool($this->makeTool('alpha'), $this->makeExecutorReturning(new CallToolResult(content: [])));

        $result = $store->call('alpha', null, $this->makeContext());

        self::assertInstanceOf(CallToolResult::class, $result);

        self::assertSame([], $result->content);
    }

    public function testConstructorRefusesAnUnconventionalName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('tool "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.');

        new ToolStore(['Project Files' => new ToolEntry(new Tool(name: 'Project Files', inputSchema: ['type' => 'object']), new ClosureToolExecutor(static fn(?array $a, ServerContext $c): CallToolResult => new CallToolResult(content: [])))]);
    }

    public function testAddRefusesAnUnconventionalName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('tool "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.');

        (new ToolStore())->addTool(new Tool(name: 'Project Files', inputSchema: ['type' => 'object']), new ClosureToolExecutor(static fn(?array $a, ServerContext $c): CallToolResult => new CallToolResult(content: [])));
    }

    public function testConstructorRefusesANonConservativeIconSrc(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('tool "icons.src" must be an HTTP/HTTPS URL or a data: URI with base64-encoded data, \'ftp://example.com/icon.png\' given.');

        new ToolStore(['t' => new ToolEntry(new Tool(name: 't', inputSchema: ['type' => 'object'], icons: [new Icon(src: 'ftp://example.com/icon.png')]), new ClosureToolExecutor(static fn(?array $a, ServerContext $c): CallToolResult => new CallToolResult(content: [])))]);
    }

    public function testAddRefusesANonConservativeIconSrc(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('tool "icons.src" must be an HTTP/HTTPS URL or a data: URI with base64-encoded data, \'ftp://example.com/icon.png\' given.');

        (new ToolStore())->addTool(new Tool(name: 't', inputSchema: ['type' => 'object'], icons: [new Icon(src: 'ftp://example.com/icon.png')]), new ClosureToolExecutor(static fn(?array $a, ServerContext $c): CallToolResult => new CallToolResult(content: [])));
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

        $store->call('digit', ['0' => 'v'], $this->makeContext());

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

        $result = $store->call('digit-only', ['a' => 'b'], $this->makeContext());

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

        $store->call('digit', ['a' => 'b'], $this->makeContext());
    }

    public function testCallWrapsABindingFailureWithTheToolName(): void
    {
        $store = new ToolStore([
            'search' => new ToolEntry(
                $this->makeTool('search'),
                new ClosureToolExecutor(static function (?array $arguments, ServerContext $context): CallToolResult {
                    throw new InvalidParamsException($context->requestId, 'missing the required "q" key.');
                }),
            ),
        ]);

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('Invalid arguments for tool "search": missing the required "q" key.');

        $store->call('search', [], $this->makeContext());
    }

    /**
     * @param non-empty-string $name
     */
    private function makeTool(string $name): Tool
    {
        return new Tool(name: $name, inputSchema: ['type' => 'object']);
    }

    /**
     * @param non-empty-string $name
     */
    private function makeToolWithOutputSchema(string $name): Tool
    {
        return new Tool(name: $name, inputSchema: ['type' => 'object'], outputSchema: [
            'type' => 'object',
            'properties' => ['n' => ['type' => 'integer']],
            'required' => ['n'],
        ]);
    }

    private function buildClosedSchemaStore(): ToolStore
    {
        return new ToolStore([
            'search' => new ToolEntry(
                new Tool(name: 'search', inputSchema: [
                    'type' => 'object',
                    'properties' => ['q' => ['type' => 'string']],
                    'additionalProperties' => false,
                ]),
                $this->makeExecutor(),
            ),
        ]);
    }

    private function makeExecutorReturning(CallToolResult $result): ClosureToolExecutor
    {
        return new ClosureToolExecutor(static fn(?array $arguments, ServerContext $context): CallToolResult => $result);
    }

    /**
     * @return array<non-empty-string, ToolEntry>
     */
    private function makeEntries(string ...$names): array
    {
        $entries = [];

        foreach ($names as $name) {
            \assert('' !== $name);
            $entries[$name] = new ToolEntry($this->makeTool($name), $this->makeExecutor());
        }

        return $entries;
    }

    private function makeExecutor(): ClosureToolExecutor
    {
        return new ClosureToolExecutor(
            static fn(?array $arguments, ServerContext $context): CallToolResult => new CallToolResult(content: []),
        );
    }

    private function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
