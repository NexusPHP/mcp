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
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestMetaObject;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use Nexus\Mcp\Server\Resource\ClosureTemplatedResourceReader;
use Nexus\Mcp\Server\Resource\ResourceTemplateStore;
use Nexus\Mcp\Server\Resource\TemplatedResourceReaderInterface;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResourceTemplateStore::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ResourceTemplateStoreTest extends TestCase
{
    public function testListTemplatesReturnsRegisteredTemplates(): void
    {
        $store = new ResourceTemplateStore([
            'file:///{name}.txt' => self::entry(new ResourceTemplate('alpha', 'file:///{name}.txt')),
            'file:///{name}.log' => self::entry(new ResourceTemplate('beta', 'file:///{name}.log')),
        ]);

        $result = $store->listTemplates(null);

        self::assertCount(2, $result->resourceTemplates);
        self::assertSame('alpha', $result->resourceTemplates[0]->name);
        self::assertSame('beta', $result->resourceTemplates[1]->name);
        self::assertNull($result->nextCursor);
    }

    public function testListTemplatesPaginatesWithCursor(): void
    {
        $store = new ResourceTemplateStore(
            [
                'file:///{x}.a' => self::entry(new ResourceTemplate('a', 'file:///{x}.a')),
                'file:///{x}.b' => self::entry(new ResourceTemplate('b', 'file:///{x}.b')),
                'file:///{x}.c' => self::entry(new ResourceTemplate('c', 'file:///{x}.c')),
            ],
            pageSize: 2,
        );

        $first = $store->listTemplates(null);
        self::assertCount(2, $first->resourceTemplates);
        self::assertNotNull($first->nextCursor);
        self::assertSame('file:///{x}.b', $first->nextCursor->cursor);

        $second = $store->listTemplates($first->nextCursor);
        self::assertCount(1, $second->resourceTemplates);
        self::assertSame('c', $second->resourceTemplates[0]->name);
        self::assertNull($second->nextCursor);
    }

    public function testListTemplatesAfterLastItemReturnsEmptyPage(): void
    {
        $store = new ResourceTemplateStore(
            ['file:///{x}.only' => self::entry(new ResourceTemplate('only', 'file:///{x}.only'))],
            pageSize: 1,
        );

        $page = $store->listTemplates(new Cursor('file:///{x}.only'));

        self::assertSame([], $page->resourceTemplates);
        self::assertNull($page->nextCursor);
    }

    public function testListTemplatesWithEmptyStoreReturnsEmptyPage(): void
    {
        $page = new ResourceTemplateStore()->listTemplates(null);

        self::assertSame([], $page->resourceTemplates);
        self::assertNull($page->nextCursor);
    }

    public function testConstructorRejectsNonPositivePageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource template store page size must be a positive integer, 0 given\.$/');

        new ResourceTemplateStore([], 0);
    }

    public function testConstructorRejectsIntegerEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource template store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new ResourceTemplateStore([1 => self::entry(new ResourceTemplate('one', 'file:///{x}.one'))]);
    }

    public function testConstructorRejectsEmptyStringEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource template store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new ResourceTemplateStore(['' => self::entry(new ResourceTemplate('one', 'file:///{x}.one'))]);
    }

    public function testConstructorRejectsEntryKeyThatDoesNotMatchTemplateUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource template store entry key "file:\/\/\/{x}\.one" must match its template URI "file:\/\/\/{y}\.one"\.$/');

        new ResourceTemplateStore([
            'file:///{x}.one' => self::entry(new ResourceTemplate('one', 'file:///{y}.one')),
        ]);
    }

    public function testConstructorRejectsTemplateWithLevel2Expression(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^ResourceTemplate URI template must use only RFC 6570 Level 1 simple-name expressions/');

        new ResourceTemplateStore([
            'file:///{+path}' => self::entry(new ResourceTemplate('paths', 'file:///{+path}')),
        ]);
    }

    public function testConstructorRejectsTemplateWithAdjacentExpressions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^ResourceTemplate URI template must include literal text between adjacent expressions/');

        new ResourceTemplateStore([
            'file:///{a}{b}' => self::entry(new ResourceTemplate('ab', 'file:///{a}{b}')),
        ]);
    }

    public function testListTemplatesRejectsCursorThatMatchesNoEntry(): void
    {
        $store = new ResourceTemplateStore([
            'file:///{x}.alpha' => self::entry(new ResourceTemplate('alpha', 'file:///{x}.alpha')),
        ]);

        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/^Cursor "missing" does not match any registered entry\.$/');

        $store->listTemplates(new Cursor('missing'));
    }

    public function testReadThrowsWhenNoTemplateMatches(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessageMatches('/^No resource registered under URI "http:\/\/example\.com\/etc"\.$/');

        $store = new ResourceTemplateStore([
            'file:///{path}' => self::entry(
                new ResourceTemplate('files', 'file:///{path}'),
                static fn(): never => throw new \LogicException('unreachable'),
            ),
        ]);
        $store->read('http://example.com/etc', self::makeContext());
    }

    public function testReadDelegatesToFirstMatchingTemplateWithBindings(): void
    {
        $captured = ['uri' => null, 'bindings' => null];
        $expected = new ReadResourceResult([new TextResourceContents('file:///etc', 'hello')]);

        $store = new ResourceTemplateStore([
            'weather://{city}/{day}' => self::entry(
                new ResourceTemplate('weather', 'weather://{city}/{day}'),
                static fn(): never => throw new \LogicException('weather template should not match'),
            ),
            'file:///{path}' => self::entry(
                new ResourceTemplate('files', 'file:///{path}'),
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
        $expected = new ReadResourceResult([new TextResourceContents('file:///x', 'ok')]);

        $store = new ResourceTemplateStore([
            'file:///{path}' => self::entry(
                new ResourceTemplate('first', 'file:///{path}'),
                static function () use (&$firstCalled, $expected): ReadResourceResult {
                    $firstCalled = true;

                    return $expected;
                },
            ),
            'file:///{other}' => self::entry(
                new ResourceTemplate('second', 'file:///{other}'),
                static fn(): never => throw new \LogicException('second template should not run after first match'),
            ),
        ]);

        self::assertSame($expected, $store->read('file:///x', self::makeContext()));
        self::assertTrue($firstCalled);
    }

    /**
     * @param null|\Closure(string, array<string, string>, ServerContext): ReadResourceResult $reader
     *
     * @return array{template: ResourceTemplate, reader: TemplatedResourceReaderInterface}
     */
    private static function entry(ResourceTemplate $template, ?\Closure $reader = null): array
    {
        return [
            'template' => $template,
            'reader' => new ClosureTemplatedResourceReader(
                $reader ?? static fn(): never => throw new \LogicException('unreachable'),
            ),
        ];
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
