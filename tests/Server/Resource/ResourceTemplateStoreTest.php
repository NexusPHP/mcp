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

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Core\Schema\Resource\ResourceTemplate;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use Nexus\Mcp\Server\Resource\ResourceTemplateStore;
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
            'alpha' => new ResourceTemplate('alpha', 'file:///{name}.txt'),
            'beta' => new ResourceTemplate('beta', 'file:///{name}.log'),
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
                'a' => new ResourceTemplate('a', 'file:///{x}.a'),
                'b' => new ResourceTemplate('b', 'file:///{x}.b'),
                'c' => new ResourceTemplate('c', 'file:///{x}.c'),
            ],
            pageSize: 2,
        );

        $first = $store->listTemplates(null);
        self::assertCount(2, $first->resourceTemplates);
        self::assertNotNull($first->nextCursor);
        self::assertSame('b', $first->nextCursor->cursor);

        $second = $store->listTemplates($first->nextCursor);
        self::assertCount(1, $second->resourceTemplates);
        self::assertSame('c', $second->resourceTemplates[0]->name);
        self::assertNull($second->nextCursor);
    }

    public function testListTemplatesAfterLastItemReturnsEmptyPage(): void
    {
        $store = new ResourceTemplateStore(
            ['only' => new ResourceTemplate('only', 'file:///{x}.only')],
            pageSize: 1,
        );

        $page = $store->listTemplates(new Cursor('only'));

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
        new ResourceTemplateStore([1 => new ResourceTemplate('one', 'file:///{x}.one')]);
    }

    public function testConstructorRejectsEmptyStringEntryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Resource template store entry key must be a non-empty string\.$/');

        // @phpstan-ignore argument.type
        new ResourceTemplateStore(['' => new ResourceTemplate('one', 'file:///{x}.one')]);
    }

    public function testListTemplatesRejectsCursorThatMatchesNoEntry(): void
    {
        $store = new ResourceTemplateStore(['alpha' => new ResourceTemplate('alpha', 'file:///{x}.alpha')]);

        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/^Cursor "missing" does not match any registered entry\.$/');

        $store->listTemplates(new Cursor('missing'));
    }
}
