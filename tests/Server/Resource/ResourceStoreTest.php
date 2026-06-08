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

namespace Nexus\Mcp\Tests\Server\Resource;

use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\AbstractPaginatedStore;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\Resource\ClosureResourceReader;
use Nexus\Mcp\Server\Resource\ResourceEntry;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResourceStore::class)]
#[CoversClass(AbstractPaginatedStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ResourceStoreTest extends TestCase
{
    public function testListReturnsRegisteredResources(): void
    {
        $store = new ResourceStore(self::makeEntries(
            ['alpha', 'file:///tmp/a.txt'],
            ['beta', 'file:///tmp/b.txt'],
        ));

        $result = $store->list(null);

        self::assertCount(2, $result->resources);
        self::assertSame('file:///tmp/a.txt', $result->resources[0]->uri);
        self::assertSame('file:///tmp/b.txt', $result->resources[1]->uri);
        self::assertNull($result->nextCursor);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testListReflectsConfiguredTtlAndCacheScope(): void
    {
        $store = new ResourceStore(
            self::makeEntries(['alpha', 'file:///tmp/a.txt']),
            ttlMs: 120000,
            cacheScope: CacheScope::Public,
        );

        $result = $store->list(null);

        self::assertSame(120000, $result->ttlMs);
        self::assertSame(CacheScope::Public, $result->cacheScope);
    }

    public function testListPaginatesWithCursor(): void
    {
        $store = new ResourceStore(
            self::makeEntries(['a', 'file:///a'], ['b', 'file:///b'], ['c', 'file:///c']),
            pageSize: 2,
        );

        $first = $store->list(null);
        self::assertCount(2, $first->resources);
        self::assertNotNull($first->nextCursor);
        self::assertSame('file:///b', $first->nextCursor->cursor);

        $second = $store->list($first->nextCursor);
        self::assertCount(1, $second->resources);
        self::assertSame('file:///c', $second->resources[0]->uri);
        self::assertNull($second->nextCursor);
    }

    public function testListAfterLastItemReturnsEmptyPage(): void
    {
        $store = new ResourceStore(self::makeEntries(['only', 'file:///only']), pageSize: 1);

        $page = $store->list(new Cursor('file:///only'));

        self::assertSame([], $page->resources);
        self::assertNull($page->nextCursor);
    }

    public function testListWithEmptyStoreReturnsEmptyPage(): void
    {
        $page = new ResourceStore()->list(null);

        self::assertSame([], $page->resources);
        self::assertNull($page->nextCursor);
    }

    public function testConstructorRejectsNonPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource store page size must be a positive integer, 0 given\.$/');

        new ResourceStore([], 0);
    }

    public function testConstructorRejectsIntegerEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new ResourceStore([1 => new ResourceEntry(new Resource('one', 'file:///one'), self::makeReader())]);
    }

    public function testConstructorRejectsEmptyStringEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new ResourceStore(['' => new ResourceEntry(new Resource('one', 'file:///one'), self::makeReader())]);
    }

    public function testListRejectsCursorThatMatchesNoEntry(): void
    {
        $store = new ResourceStore(self::makeEntries(['alpha', 'file:///alpha']));

        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/^Cursor "file:\\/\\/\\/missing" does not match any registered entry\.$/');

        $store->list(new Cursor('file:///missing'));
    }

    public function testReadInvokesTheReaderMatchingTheUri(): void
    {
        $alphaResult = new ReadResourceResult([], 0, CacheScope::Private);
        $betaResult = new ReadResourceResult([], 0, CacheScope::Private);
        $captured = [];
        $store = new ResourceStore([
            'file:///alpha.txt' => new ResourceEntry(
                new Resource('alpha', 'file:///alpha.txt'),
                new ClosureResourceReader(static function (string $uri, ServerContext $context) use ($alphaResult, &$captured): ReadResourceResult {
                    $captured[] = ['key' => 'alpha', 'uri' => $uri, 'sessionId' => $context->sessionId];

                    return $alphaResult;
                }),
            ),
            'file:///beta.txt' => new ResourceEntry(
                new Resource('beta', 'file:///beta.txt'),
                new ClosureResourceReader(static function (string $uri, ServerContext $context) use ($betaResult, &$captured): ReadResourceResult {
                    $captured[] = ['key' => 'beta', 'uri' => $uri, 'sessionId' => $context->sessionId];

                    return $betaResult;
                }),
            ),
        ]);

        self::assertSame($betaResult, $store->read('file:///beta.txt', self::makeContext()));
        self::assertSame($alphaResult, $store->read('file:///alpha.txt', self::makeContext()));
        self::assertSame([
            ['key' => 'beta', 'uri' => 'file:///beta.txt', 'sessionId' => null],
            ['key' => 'alpha', 'uri' => 'file:///alpha.txt', 'sessionId' => null],
        ], $captured);
    }

    public function testReadThrowsForUnknownUri(): void
    {
        $store = new ResourceStore();

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No resource registered under URI "file:\\/\\/\\/missing"\.$/');

        $store->read('file:///missing', self::makeContext());
    }

    /**
     * @param array{non-empty-string, non-empty-string} ...$pairs
     *
     * @return array<non-empty-string, ResourceEntry>
     */
    private static function makeEntries(array ...$pairs): array
    {
        $entries = [];

        foreach ($pairs as [$name, $uri]) {
            $entries[$uri] = new ResourceEntry(new Resource($name, $uri), self::makeReader());
        }

        return $entries;
    }

    private static function makeReader(): ClosureResourceReader
    {
        return new ClosureResourceReader(
            static fn(string $uri, ServerContext $context): ReadResourceResult => new ReadResourceResult([], 0, CacheScope::Private),
        );
    }

    private static function makeContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(1),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            null,
            new RecordingSender(),
        );
    }
}
