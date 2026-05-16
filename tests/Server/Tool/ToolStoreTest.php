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
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Server\Tool\ClosureToolExecutor;
use Nexus\Mcp\Server\Tool\ToolEntry;
use Nexus\Mcp\Server\Tool\ToolStore;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ToolStore::class)]
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

        $page = $store->list(new Cursor('only'));

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

        $store->list(new Cursor('missing'));
    }

    public function testCallInvokesTheExecutorMatchingTheName(): void
    {
        $alphaResult = new CallToolResult([]);
        $betaResult = new CallToolResult([]);
        $captured = [];
        $store = new ToolStore([
            'alpha' => new ToolEntry(
                self::makeTool('alpha'),
                new ClosureToolExecutor(static function (?array $arguments, ServerContext $context) use ($alphaResult, &$captured): CallToolResult {
                    $captured[] = ['name' => 'alpha', 'arguments' => $arguments, 'sessionId' => $context->sessionId];

                    return $alphaResult;
                }),
            ),
            'beta' => new ToolEntry(
                self::makeTool('beta'),
                new ClosureToolExecutor(static function (?array $arguments, ServerContext $context) use ($betaResult, &$captured): CallToolResult {
                    $captured[] = ['name' => 'beta', 'arguments' => $arguments, 'sessionId' => $context->sessionId];

                    return $betaResult;
                }),
            ),
        ]);

        self::assertSame($betaResult, $store->call('beta', ['key' => 'value'], self::makeContext()));
        self::assertSame($alphaResult, $store->call('alpha', null, self::makeContext()));
        self::assertSame([
            ['name' => 'beta', 'arguments' => ['key' => 'value'], 'sessionId' => null],
            ['name' => 'alpha', 'arguments' => null, 'sessionId' => null],
        ], $captured);
    }

    public function testCallThrowsForUnknownToolName(): void
    {
        $store = new ToolStore();

        $this->expectException(ToolNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No tool registered under name "missing"\.$/');

        $store->call('missing', null, self::makeContext());
    }

    private static function makeTool(string $name): Tool
    {
        return new Tool($name, ['type' => 'object']);
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
            static fn(?array $arguments, ServerContext $context): CallToolResult => new CallToolResult([]),
        );
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            new RequestMetaObject(),
            null,
            new RecordingSender(),
        );
    }
}
