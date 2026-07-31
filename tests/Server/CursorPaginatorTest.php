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

namespace Nexus\Mcp\Tests\Server;

use Nexus\Mcp\Core\Schema\Cursor;
use Nexus\Mcp\Server\CursorPaginator;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CursorPaginator::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CursorPaginatorTest extends TestCase
{
    public function testReturnsEveryEntryWhenTheyFitOnOnePage(): void
    {
        $entries = ['alpha' => new \stdClass(), 'beta' => new \stdClass()];

        $page = new CursorPaginator(50)->paginate($entries, null);

        self::assertSame([$entries['alpha'], $entries['beta']], $page->entries);
        self::assertNull($page->nextCursor);
    }

    public function testResumesFromADecimalIntStringKeyPhpTurnedIntoAnInt(): void
    {
        // An all-digit entry name is a legal identifier, and PHP stores it as an int key. The cursor is a
        // string either way, so resolution has to compare the keys stringified.
        $entries = ['123' => new \stdClass(), 'beta' => new \stdClass()];
        $paginator = new CursorPaginator(1);

        $first = $paginator->paginate($entries, null);
        self::assertNotNull($first->nextCursor);
        self::assertSame('123', $first->nextCursor->cursor);

        $second = $paginator->paginate($entries, $first->nextCursor);

        self::assertSame([$entries['beta']], $second->entries);
        self::assertNull($second->nextCursor);
    }

    public function testReturnsAnEmptyPageForAnEmptyStore(): void
    {
        $page = new CursorPaginator(50)->paginate([], null);

        self::assertSame([], $page->entries);
        self::assertNull($page->nextCursor);
    }

    public function testCapsThePageAndNamesTheNextCursorAfterItsLastEntry(): void
    {
        $entries = ['alpha' => new \stdClass(), 'beta' => new \stdClass(), 'gamma' => new \stdClass()];

        $page = new CursorPaginator(2)->paginate($entries, null);

        self::assertSame([$entries['alpha'], $entries['beta']], $page->entries);

        if (! $page->nextCursor instanceof Cursor) {
            self::fail('Expected a next cursor when entries remain beyond the page.');
        }

        self::assertSame('beta', $page->nextCursor->cursor);
    }

    public function testWithholdsACursorWhenTheLastPageIsExactlyFull(): void
    {
        $entries = ['alpha' => new \stdClass(), 'beta' => new \stdClass()];

        $page = new CursorPaginator(2)->paginate($entries, null);

        self::assertCount(2, $page->entries);
        self::assertNull($page->nextCursor);
    }

    public function testResumesFromTheEntryAfterTheCursor(): void
    {
        $entries = [
            'alpha' => new \stdClass(),
            'beta' => new \stdClass(),
            'gamma' => new \stdClass(),
            'delta' => new \stdClass(),
        ];

        $page = new CursorPaginator(2)->paginate($entries, new Cursor(cursor: 'beta'));

        self::assertSame([$entries['gamma'], $entries['delta']], $page->entries);
        self::assertNull($page->nextCursor);
    }

    public function testReturnsAnEmptyPageWhenTheCursorNamesTheFinalEntry(): void
    {
        $entries = ['alpha' => new \stdClass(), 'beta' => new \stdClass()];

        $page = new CursorPaginator(2)->paginate($entries, new Cursor(cursor: 'beta'));

        self::assertSame([], $page->entries);
        self::assertNull($page->nextCursor);
    }

    public function testKeysThePageAsAListRatherThanPreservingEntryKeys(): void
    {
        $entries = ['alpha' => new \stdClass(), 'beta' => new \stdClass()];

        $page = new CursorPaginator(50)->paginate($entries, null);

        self::assertSame([0, 1], array_keys($page->entries));
    }

    public function testRejectsACursorNoEntryMatches(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageIs('Cursor "missing" does not match any registered entry.');

        new CursorPaginator(50)->paginate(['alpha' => new \stdClass()], new Cursor(cursor: 'missing'));
    }
}
