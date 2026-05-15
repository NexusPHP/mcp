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

namespace Nexus\Mcp\Tests\Server\Prompt;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\PromptNotFoundException;
use Nexus\Mcp\Server\Prompt\PromptStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PromptStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class PromptStoreTest extends TestCase
{
    public function testListReturnsRegisteredPrompts(): void
    {
        $store = new PromptStore(self::makeEntries('alpha', 'beta'));

        $result = $store->list(null);

        self::assertCount(2, $result->prompts);
        self::assertSame('alpha', $result->prompts[0]->name);
        self::assertSame('beta', $result->prompts[1]->name);
        self::assertNull($result->nextCursor);
    }

    public function testListPaginatesWithCursor(): void
    {
        $store = new PromptStore(self::makeEntries('a', 'b', 'c'), pageSize: 2);

        $first = $store->list(null);
        self::assertCount(2, $first->prompts);
        self::assertNotNull($first->nextCursor);
        self::assertSame('b', $first->nextCursor->cursor);

        $second = $store->list($first->nextCursor);
        self::assertCount(1, $second->prompts);
        self::assertSame('c', $second->prompts[0]->name);
        self::assertNull($second->nextCursor);
    }

    public function testListAfterLastItemReturnsEmptyPage(): void
    {
        $store = new PromptStore(self::makeEntries('only'), pageSize: 1);

        $page = $store->list(new Cursor('only'));

        self::assertSame([], $page->prompts);
        self::assertNull($page->nextCursor);
    }

    public function testListWithEmptyStoreReturnsEmptyPage(): void
    {
        $page = new PromptStore()->list(null);

        self::assertSame([], $page->prompts);
        self::assertNull($page->nextCursor);
    }

    public function testConstructorRejectsNonPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Prompt store page size must be a positive integer, 0 given\.$/');

        new PromptStore([], 0);
    }

    public function testConstructorRejectsIntegerEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Prompt store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new PromptStore([1 => ['prompt' => new Prompt('one'), 'renderer' => self::makeRenderer()]]);
    }

    public function testConstructorRejectsEmptyStringEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Prompt store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new PromptStore(['' => ['prompt' => new Prompt('one'), 'renderer' => self::makeRenderer()]]);
    }

    public function testListRejectsCursorThatMatchesNoEntry(): void
    {
        $store = new PromptStore(self::makeEntries('alpha'));

        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/^Cursor "missing" does not match any registered entry\.$/');

        $store->list(new Cursor('missing'));
    }

    public function testGetInvokesTheRendererMatchingTheName(): void
    {
        $alphaResult = new GetPromptResult([]);
        $betaResult = new GetPromptResult([]);
        $captured = [];
        $store = new PromptStore([
            'alpha' => [
                'prompt' => new Prompt('alpha'),
                'renderer' => static function (?array $arguments, ServerContext $context) use ($alphaResult, &$captured): GetPromptResult {
                    $captured[] = ['name' => 'alpha', 'arguments' => $arguments, 'sessionId' => $context->sessionId];

                    return $alphaResult;
                },
            ],
            'beta' => [
                'prompt' => new Prompt('beta'),
                'renderer' => static function (?array $arguments, ServerContext $context) use ($betaResult, &$captured): GetPromptResult {
                    $captured[] = ['name' => 'beta', 'arguments' => $arguments, 'sessionId' => $context->sessionId];

                    return $betaResult;
                },
            ],
        ]);

        self::assertSame($betaResult, $store->get('beta', ['name' => 'World'], self::makeContext()));
        self::assertSame($alphaResult, $store->get('alpha', null, self::makeContext()));
        self::assertSame([
            ['name' => 'beta', 'arguments' => ['name' => 'World'], 'sessionId' => null],
            ['name' => 'alpha', 'arguments' => null, 'sessionId' => null],
        ], $captured);
    }

    public function testGetThrowsForUnknownPromptName(): void
    {
        $store = new PromptStore();

        $this->expectException(PromptNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No prompt registered under name "missing"\.$/');

        $store->get('missing', null, self::makeContext());
    }

    /**
     * @return array<non-empty-string, array{prompt: Prompt, renderer: \Closure(?array<string, string>, ServerContext): GetPromptResult}>
     */
    private static function makeEntries(string ...$names): array
    {
        $entries = [];

        foreach ($names as $name) {
            \assert('' !== $name);
            $entries[$name] = ['prompt' => new Prompt($name), 'renderer' => self::makeRenderer()];
        }

        return $entries;
    }

    /**
     * @return \Closure(?array<string, string>, ServerContext): GetPromptResult
     */
    private static function makeRenderer(): \Closure
    {
        return static fn(?array $arguments, ServerContext $context): GetPromptResult => new GetPromptResult([]);
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
