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
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\Resource\ClosureTemplatedResourceReader;
use Nexus\Mcp\Server\Resource\ResourceTemplateEntry;
use Nexus\Mcp\Server\Resource\ResourceTemplateStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ResourceTemplateStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ResourceTemplateStoreTest extends AbstractMcpTestCase
{
    public function testListReturnsRegisteredTemplates(): void
    {
        $store = new ResourceTemplateStore([
            'file:///{name}.txt' => self::entry(new ResourceTemplate(name: 'alpha', uriTemplate: 'file:///{name}.txt')),
            'file:///{name}.log' => self::entry(new ResourceTemplate(name: 'beta', uriTemplate: 'file:///{name}.log')),
        ]);

        $result = $store->list(null);

        self::assertCount(2, $result->resourceTemplates);
        self::assertSame('alpha', $result->resourceTemplates[0]->name);
        self::assertSame('beta', $result->resourceTemplates[1]->name);
        self::assertNull($result->nextCursor);
        self::assertSame(0, $result->ttlMs);
        self::assertSame(CacheScope::Private, $result->cacheScope);
    }

    public function testListReflectsConfiguredTtlAndCacheScope(): void
    {
        $store = new ResourceTemplateStore(
            ['file:///{name}.txt' => self::entry(new ResourceTemplate(name: 'alpha', uriTemplate: 'file:///{name}.txt'))],
            ttlMs: 120_000,
            cacheScope: CacheScope::Public,
        );

        $result = $store->list(null);

        self::assertSame(120_000, $result->ttlMs);
        self::assertSame(CacheScope::Public, $result->cacheScope);
    }

    public function testListPaginatesWithCursor(): void
    {
        $store = new ResourceTemplateStore(
            [
                'file:///{x}.a' => self::entry(new ResourceTemplate(name: 'a', uriTemplate: 'file:///{x}.a')),
                'file:///{x}.b' => self::entry(new ResourceTemplate(name: 'b', uriTemplate: 'file:///{x}.b')),
                'file:///{x}.c' => self::entry(new ResourceTemplate(name: 'c', uriTemplate: 'file:///{x}.c')),
            ],
            pageSize: 2,
        );

        $first = $store->list(null);
        self::assertCount(2, $first->resourceTemplates);
        self::assertNotNull($first->nextCursor);
        self::assertSame('file:///{x}.b', $first->nextCursor->cursor);

        $second = $store->list($first->nextCursor);
        self::assertCount(1, $second->resourceTemplates);
        self::assertSame('c', $second->resourceTemplates[0]->name);
        self::assertNull($second->nextCursor);
    }

    public function testConstructorRejectsNonPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource template store page size must be a positive integer, 0 given\.$/');

        new ResourceTemplateStore([], 0);
    }

    public function testConstructorRejectsNegativeTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Resource template store TTL must be a non-negative integer, -1 given.');

        new ResourceTemplateStore(ttlMs: -1);
    }

    public function testConstructorRejectsIntegerEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource template store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new ResourceTemplateStore([1 => self::entry(new ResourceTemplate(name: 'one', uriTemplate: 'file:///{x}.one'))]);
    }

    public function testConstructorRejectsEmptyStringEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource template store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new ResourceTemplateStore(['' => self::entry(new ResourceTemplate(name: 'one', uriTemplate: 'file:///{x}.one'))]);
    }

    public function testConstructorRejectsEntryKeyThatDoesNotMatchTemplateUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource template store entry key "\'file:\/\/\/{x}\.one\'" must match its template URI "\'file:\/\/\/{y}\.one\'"\.$/');

        new ResourceTemplateStore([
            'file:///{x}.one' => self::entry(new ResourceTemplate(name: 'one', uriTemplate: 'file:///{y}.one')),
        ]);
    }

    public function testConstructorRejectsTemplateWithLevel2Expression(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^ResourceTemplate URI template must use only RFC 6570 Level 1 simple-name expressions/');

        new ResourceTemplateStore([
            'file:///{+path}' => self::entry(new ResourceTemplate(name: 'paths', uriTemplate: 'file:///{+path}')),
        ]);
    }

    public function testConstructorRejectsTemplateWithAdjacentExpressions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^ResourceTemplate URI template must include literal text between adjacent expressions/');

        new ResourceTemplateStore([
            'file:///{a}{b}' => self::entry(new ResourceTemplate(name: 'ab', uriTemplate: 'file:///{a}{b}')),
        ]);
    }

    public function testReadThrowsWhenNoTemplateMatches(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageMatches('/^Resource "http:\/\/example\.com\/etc" not found\.$/');

        $store = new ResourceTemplateStore([
            'file:///{path}' => self::entry(
                new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
                static fn(): never => throw new \LogicException('unreachable'),
            ),
        ]);
        $store->read('http://example.com/etc', self::makeContext());
    }

    public function testReadNeverHandsAReaderAnEncodedTraversal(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageMatches('/^Resource "file:\/\/\/%2E%2E%2F%2E%2E%2Fetc%2Fpasswd" not found\.$/');

        $store = new ResourceTemplateStore([
            'file:///{path}' => self::entry(
                new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
                static fn(): never => throw new \LogicException('the reader must not be reached'),
            ),
        ]);
        $store->read('file:///%2E%2E%2F%2E%2E%2Fetc%2Fpasswd', self::makeContext());
    }

    public function testReadDelegatesToFirstMatchingTemplateWithBindings(): void
    {
        $captured = ['uri' => null, 'bindings' => null];
        $expected = new ReadResourceResult(contents: [new TextResourceContents(uri: 'file:///etc', text: 'hello')], ttlMs: 0, cacheScope: CacheScope::Private);

        $store = new ResourceTemplateStore([
            'weather://{city}/{day}' => self::entry(
                new ResourceTemplate(name: 'weather', uriTemplate: 'weather://{city}/{day}'),
                static fn(): never => throw new \LogicException('weather template should not match'),
            ),
            'file:///{path}' => self::entry(
                new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
                static function (string $uri, array $bindings) use (&$captured, $expected): ReadResourceResult {
                    $captured['uri'] = $uri;
                    $captured['bindings'] = $bindings;

                    return $expected;
                },
            ),
        ]);

        $result = $store->read('file:///etc', self::makeContext());

        self::assertSame($expected, $result);
        self::assertSame('file:///etc', $captured['uri']);
        self::assertSame(['path' => 'etc'], $captured['bindings']);
    }

    public function testReadStopsAtFirstMatch(): void
    {
        $firstCalled = false;
        $expected = new ReadResourceResult(contents: [new TextResourceContents(uri: 'file:///x', text: 'ok')], ttlMs: 0, cacheScope: CacheScope::Private);

        $store = new ResourceTemplateStore([
            'file:///{path}' => self::entry(
                new ResourceTemplate(name: 'first', uriTemplate: 'file:///{path}'),
                static function () use (&$firstCalled, $expected): ReadResourceResult {
                    $firstCalled = true;

                    return $expected;
                },
            ),
            'file:///{other}' => self::entry(
                new ResourceTemplate(name: 'second', uriTemplate: 'file:///{other}'),
                static fn(): never => throw new \LogicException('second template should not run after first match'),
            ),
        ]);

        self::assertSame($expected, $store->read('file:///x', self::makeContext()));
        self::assertTrue($firstCalled);
    }

    public function testAddResourceTemplateRegistersItAndAnnouncesTheChange(): void
    {
        $store = new ResourceTemplateStore();
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        $store->addResourceTemplate(
            new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
            new ClosureTemplatedResourceReader(
                static fn(string $uri, array $bindings, ServerContext $context): ReadResourceResult => new ReadResourceResult(
                    contents: [],
                    ttlMs: 0,
                    cacheScope: CacheScope::Private,
                ),
            ),
        );

        self::assertSame(
            ['file:///{path}'],
            array_map(
                static fn(ResourceTemplate $template): string => $template->uriTemplate,
                $store->list(null)->resourceTemplates,
            ),
        );
        self::assertSame(1, $changes);

        $result = $store->read('file:///a', self::makeContext());

        if (! $result instanceof ReadResourceResult) {
            self::fail('Expected a resource result.');
        }

        self::assertSame([], $result->contents);
    }

    public function testAddResourceTemplateRejectsAnUnmatchableTemplate(): void
    {
        $store = new ResourceTemplateStore();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^ResourceTemplate URI template must use only RFC 6570 Level 1 simple-name expressions/');

        $store->addResourceTemplate(
            new ResourceTemplate(name: 'files', uriTemplate: 'file:///{+path}'),
            new ClosureTemplatedResourceReader(static fn(): never => throw new \LogicException('unreachable')),
        );
    }

    public function testRemoveResourceTemplateDropsItAndStopsMatchingIt(): void
    {
        $template = new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}');
        $store = new ResourceTemplateStore(['file:///{path}' => self::entry($template)]);
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        self::assertTrue($store->removeResourceTemplate('file:///{path}'));
        self::assertSame([], $store->list(null)->resourceTemplates);
        self::assertSame(1, $changes);

        $this->expectException(ResourceNotFoundException::class);
        $store->read('file:///a', self::makeContext());
    }

    public function testRemoveResourceTemplateIsSilentWhenNoTemplateMatches(): void
    {
        $store = new ResourceTemplateStore([
            'file:///{path}' => self::entry(new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}')),
        ]);
        $changes = 0;
        $store->onListChanged(static function () use (&$changes): void { ++$changes; });

        self::assertFalse($store->removeResourceTemplate('file:///{missing}'));
        self::assertCount(1, $store->list(null)->resourceTemplates);
        self::assertSame(0, $changes);
    }

    public function testEveryRegisteredListenerHearsAChange(): void
    {
        $store = new ResourceTemplateStore();
        $heard = [];
        $store->onListChanged(static function () use (&$heard): void { $heard[] = 'first'; });
        $store->onListChanged(static function () use (&$heard): void { $heard[] = 'second'; });

        $store->addResourceTemplate(
            new ResourceTemplate(name: 'files', uriTemplate: 'file:///{path}'),
            new ClosureTemplatedResourceReader(static fn(): never => throw new \LogicException('unreachable')),
        );

        self::assertSame(['first', 'second'], $heard);
    }

    public function testConstructorRefusesAnUnconventionalName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource template "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.');

        new ResourceTemplateStore(['mem://{p}' => self::entry(new ResourceTemplate(name: 'Project Files', uriTemplate: 'mem://{p}'))]);
    }

    public function testAddRefusesAnUnconventionalName(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('resource template "name" must be 1-128 characters of A-Z, a-z, 0-9, ".", "-", or "_", \'Project Files\' given.');

        (new ResourceTemplateStore())->addResourceTemplate(new ResourceTemplate(name: 'Project Files', uriTemplate: 'mem://{p}'), new ClosureTemplatedResourceReader(static fn(): never => throw new \LogicException('unreachable')));
    }

    /**
     * @param null|\Closure(string, array<string, string>, ServerContext): ReadResourceResult $reader
     */
    private static function entry(ResourceTemplate $template, ?\Closure $reader = null): ResourceTemplateEntry
    {
        return new ResourceTemplateEntry(
            $template,
            new ClosureTemplatedResourceReader(
                $reader ?? static fn(): never => throw new \LogicException('unreachable'),
            ),
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
