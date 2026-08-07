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
use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\Resource\ClosureResourceReader;
use Nexus\Mcp\Server\Resource\ResourceEntry;
use Nexus\Mcp\Server\Resource\ResourceStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ResourceStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ResourceStoreTest extends AbstractMcpTestCase
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
            ttlMs: 120_000,
            cacheScope: CacheScope::Public,
        );

        $result = $store->list(null);

        self::assertSame(120_000, $result->ttlMs);
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

    public function testConstructorRejectsNonPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource store page size must be a positive integer, 0 given\.$/');

        new ResourceStore([], 0);
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Resource store TTL must be a non-negative integer, -1 given.');

        new ResourceStore(ttlMs: -1);
    }

    public function testConstructorRejectsIntegerEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new ResourceStore([1 => new ResourceEntry(new Resource(name: 'one', uri: 'file:///one'), self::makeReader())]);
    }

    public function testConstructorRejectsEmptyStringEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new ResourceStore(['' => new ResourceEntry(new Resource(name: 'one', uri: 'file:///one'), self::makeReader())]);
    }

    public function testReadInvokesTheReaderMatchingTheUri(): void
    {
        $alphaResult = new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private);
        $betaResult = new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private);
        $captured = [];
        $store = new ResourceStore([
            'file:///alpha.txt' => new ResourceEntry(
                new Resource(name: 'alpha', uri: 'file:///alpha.txt'),
                new ClosureResourceReader(static function (string $uri, ServerContext $context) use ($alphaResult, &$captured): ReadResourceResult {
                    $captured[] = ['key' => 'alpha', 'uri' => $uri, 'requestId' => $context->requestId->id];

                    return $alphaResult;
                }),
            ),
            'file:///beta.txt' => new ResourceEntry(
                new Resource(name: 'beta', uri: 'file:///beta.txt'),
                new ClosureResourceReader(static function (string $uri, ServerContext $context) use ($betaResult, &$captured): ReadResourceResult {
                    $captured[] = ['key' => 'beta', 'uri' => $uri, 'requestId' => $context->requestId->id];

                    return $betaResult;
                }),
            ),
        ]);

        self::assertSame($betaResult, $store->read('file:///beta.txt', self::makeContext()));
        self::assertSame($alphaResult, $store->read('file:///alpha.txt', self::makeContext()));
        self::assertSame([
            ['key' => 'beta', 'uri' => 'file:///beta.txt', 'requestId' => 1],
            ['key' => 'alpha', 'uri' => 'file:///alpha.txt', 'requestId' => 1],
        ], $captured);
    }

    public function testReadThrowsForUnknownUri(): void
    {
        $store = new ResourceStore();

        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No resource registered under URI "file:\\/\\/\\/missing"\.$/');

        $store->read('file:///missing', self::makeContext());
    }

    public function testAddResourceRegistersItAndAnnouncesTheChange(): void
    {
        $store = new ResourceStore(self::makeEntries(['cfg', 'file:///a']));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        $store->addResource(new Resource(name: 'log', uri: 'file:///b'), self::makeReader());

        self::assertSame(
            ['file:///a', 'file:///b'],
            array_map(static fn(Resource $resource): string => $resource->uri, $store->list(null)->resources),
        );
        self::assertSame(1, $changes);
    }

    public function testAddResourceReplacesAResourceOfTheSameUri(): void
    {
        $store = new ResourceStore(self::makeEntries(['cfg', 'file:///a']));

        $store->addResource(new Resource(name: 'renamed', uri: 'file:///a'), self::makeReader());

        $resources = $store->list(null)->resources;
        self::assertCount(1, $resources);
        self::assertSame('renamed', $resources[0]->name);
    }

    public function testRemoveResourceDropsItAndAnnouncesTheChange(): void
    {
        $store = new ResourceStore(self::makeEntries(['cfg', 'file:///a'], ['log', 'file:///b']));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        self::assertTrue($store->removeResource('file:///a'));
        self::assertSame(
            ['file:///b'],
            array_map(static fn(Resource $resource): string => $resource->uri, $store->list(null)->resources),
        );
        self::assertSame(1, $changes);
    }

    public function testRemoveResourceIsSilentWhenNoResourceMatches(): void
    {
        $store = new ResourceStore(self::makeEntries(['cfg', 'file:///a']));
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        self::assertFalse($store->removeResource('file:///missing'));
        self::assertCount(1, $store->list(null)->resources);
        self::assertSame(0, $changes);
    }

    public function testEveryRegisteredListenerHearsAChange(): void
    {
        $store = new ResourceStore();
        $heard = [];
        $store->onListChanged(static function () use (&$heard): void { $heard[] = 'first'; });
        $store->onListChanged(static function () use (&$heard): void { $heard[] = 'second'; });

        $store->addResource(new Resource(name: 'cfg', uri: 'file:///a'), self::makeReader());

        self::assertSame(['first', 'second'], $heard);
    }

    public function testAnAddedResourceIsReadable(): void
    {
        $store = new ResourceStore();
        $store->addResource(new Resource(name: 'cfg', uri: 'file:///a'), self::makeReader());

        $result = $store->read('file:///a', self::makeContext());

        if (! $result instanceof ReadResourceResult) {
            self::fail('Expected a resource result.');
        }

        self::assertSame([], $result->contents);
    }

    public function testConstructorRefusesAnUnconventionalName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.');

        new ResourceStore(['file:///x' => new ResourceEntry(new Resource(name: 'Project Files', uri: 'file:///x'), self::makeReader())]);
    }

    public function testAddRefusesAnUnconventionalName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.');

        (new ResourceStore())->addResource(new Resource(name: 'Project Files', uri: 'file:///x'), self::makeReader());
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
            $entries[$uri] = new ResourceEntry(new Resource(name: $name, uri: $uri), self::makeReader());
        }

        return $entries;
    }

    private static function makeReader(): ClosureResourceReader
    {
        return new ClosureResourceReader(
            static fn(string $uri, ServerContext $context): ReadResourceResult => new ReadResourceResult(contents: [], ttlMs: 0, cacheScope: CacheScope::Private),
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
