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
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Prompt\Prompt;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\GetPromptResult;
use Nexus\Mcp\Server\Exception\PromptNotFoundException;
use Nexus\Mcp\Server\Prompt\ClosurePromptRenderer;
use Nexus\Mcp\Server\Prompt\PromptEntry;
use Nexus\Mcp\Server\Prompt\PromptStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(PromptStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class PromptStoreTest extends AbstractMcpTestCase
{
    public function testListReturnsRegisteredPrompts(): void
    {
        $store = new PromptStore(self::makeEntries('alpha', 'beta'));

        $result = $store->list(null);

        self::assertCount(2, $result->prompts);
        self::assertSame('alpha', $result->prompts[0]->name);
        self::assertSame('beta', $result->prompts[1]->name);
        self::assertNull($result->nextCursor);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
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

    public function testConstructorRejectsNonPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Prompt store page size must be a positive integer, 0 given\.$/');

        new PromptStore([], 0);
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Prompt store TTL must be a non-negative integer, -1 given.');

        new PromptStore(ttlMs: -1);
    }

    public function testConstructorRejectsAnEntryKeyThatDoesNotMatchItsName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Prompt store entry key "\'mismatch\'" must match its prompt name "\'one\'".');

        new PromptStore(['mismatch' => new PromptEntry(new Prompt(name: 'one'), self::makeRenderer())]);
    }

    public function testAnAllDigitNameIsServedDespiteBecomingAnIntegerKey(): void
    {
        // The name rules permit all digits, and PHP turns such a key into an int. Pagination must still
        // mint a cursor that names the entry rather than its position.
        $store = new PromptStore(['123' => new PromptEntry(new Prompt(name: '123'), self::makeRenderer()), 'beta' => new PromptEntry(new Prompt(name: 'beta'), self::makeRenderer())], pageSize: 1);

        $first = $store->list(null);
        self::assertNotNull($first->nextCursor);
        self::assertSame('123', $first->nextCursor->cursor);

        $second = $store->list($first->nextCursor);
        self::assertSame(
            ['beta'],
            array_map(static fn(Prompt $e): string => $e->name, $second->prompts),
        );
    }

    public function testGetInvokesTheRendererMatchingTheName(): void
    {
        $alphaResult = new GetPromptResult(messages: []);
        $betaResult = new GetPromptResult(messages: []);
        $captured = [];
        $store = new PromptStore([
            'alpha' => new PromptEntry(
                new Prompt(name: 'alpha'),
                new ClosurePromptRenderer(static function (?array $arguments, ServerContext $context) use ($alphaResult, &$captured): GetPromptResult {
                    $captured[] = ['name' => 'alpha', 'arguments' => $arguments, 'requestId' => $context->requestId->id];

                    return $alphaResult;
                }),
            ),
            'beta' => new PromptEntry(
                new Prompt(name: 'beta'),
                new ClosurePromptRenderer(static function (?array $arguments, ServerContext $context) use ($betaResult, &$captured): GetPromptResult {
                    $captured[] = ['name' => 'beta', 'arguments' => $arguments, 'requestId' => $context->requestId->id];

                    return $betaResult;
                }),
            ),
        ]);

        self::assertSame($betaResult, $store->get('beta', ['name' => 'World'], self::makeContext()));
        self::assertSame($alphaResult, $store->get('alpha', null, self::makeContext()));
        self::assertSame([
            ['name' => 'beta', 'arguments' => ['name' => 'World'], 'requestId' => 1],
            ['name' => 'alpha', 'arguments' => null, 'requestId' => 1],
        ], $captured);
    }

    public function testGetThrowsForUnknownPromptName(): void
    {
        $store = new PromptStore();

        $this->expectException(PromptNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No prompt registered under name "missing"\.$/');

        $store->get('missing', null, self::makeContext());
    }

    public function testAddPromptRegistersItAndAnnouncesTheChange(): void
    {
        $store = new PromptStore(self::makeEntries('alpha'));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        $store->addPrompt(new Prompt(name: 'beta'), self::makeRenderer());

        self::assertSame(
            ['alpha', 'beta'],
            array_map(static fn(Prompt $prompt): string => $prompt->name, $store->list(null)->prompts),
        );
        self::assertSame(1, $changes);
    }

    public function testAddPromptReplacesAPromptOfTheSameName(): void
    {
        $store = new PromptStore(self::makeEntries('alpha'));

        $store->addPrompt(new Prompt(name: 'alpha', title: 'Renamed'), self::makeRenderer());

        $prompts = $store->list(null)->prompts;
        self::assertCount(1, $prompts);
        self::assertSame('Renamed', $prompts[0]->title);
    }

    public function testRemovePromptDropsItAndAnnouncesTheChange(): void
    {
        $store = new PromptStore(self::makeEntries('alpha', 'beta'));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        self::assertTrue($store->removePrompt('alpha'));
        self::assertSame(
            ['beta'],
            array_map(static fn(Prompt $prompt): string => $prompt->name, $store->list(null)->prompts),
        );
        self::assertSame(1, $changes);
    }

    public function testRemovePromptIsSilentWhenNoPromptMatches(): void
    {
        $store = new PromptStore(self::makeEntries('alpha'));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        self::assertFalse($store->removePrompt('missing'));
        self::assertCount(1, $store->list(null)->prompts);
        self::assertSame(0, $changes);
    }

    public function testEveryRegisteredListenerHearsAChange(): void
    {
        $store = new PromptStore();
        $heard = [];
        $store->onListChanged(static function () use (&$heard): void { $heard[] = 'first'; });
        $store->onListChanged(static function () use (&$heard): void { $heard[] = 'second'; });

        $store->addPrompt(new Prompt(name: 'alpha'), self::makeRenderer());

        self::assertSame(['first', 'second'], $heard);
    }

    public function testAnAddedPromptIsRenderable(): void
    {
        $store = new PromptStore();
        $store->addPrompt(new Prompt(name: 'alpha'), self::makeRenderer());

        $result = $store->get('alpha', null, self::makeContext());

        if (! $result instanceof GetPromptResult) {
            self::fail('Expected a prompt result.');
        }

        self::assertSame([], $result->messages);
    }

    public function testConstructorRefusesAnUnconventionalName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('prompt "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.');

        new PromptStore(['Project Files' => new PromptEntry(new Prompt(name: 'Project Files'), self::makeRenderer())]);
    }

    public function testAddRefusesAnUnconventionalName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('prompt "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.');

        (new PromptStore())->addPrompt(new Prompt(name: 'Project Files'), self::makeRenderer());
    }

    /**
     * @return array<non-empty-string, PromptEntry>
     */
    private static function makeEntries(string ...$names): array
    {
        $entries = [];

        foreach ($names as $name) {
            \assert('' !== $name);
            $entries[$name] = new PromptEntry(new Prompt(name: $name), self::makeRenderer());
        }

        return $entries;
    }

    private static function makeRenderer(): ClosurePromptRenderer
    {
        return new ClosurePromptRenderer(
            static fn(?array $arguments, ServerContext $context): GetPromptResult => new GetPromptResult(messages: []),
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
