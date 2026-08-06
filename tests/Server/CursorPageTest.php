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
use Nexus\Mcp\Server\CursorPage;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(CursorPage::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class CursorPageTest extends AbstractMcpTestCase
{
    public function testCarriesItsEntriesAndNextCursor(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();
        $cursor = new Cursor(cursor: 'beta');

        $page = new CursorPage([$first, $second], $cursor);

        self::assertSame([$first, $second], $page->entries);
        self::assertSame($cursor, $page->nextCursor);
    }

    public function testCarriesAnEmptyFinalPage(): void
    {
        $page = new CursorPage([], null);

        self::assertSame([], $page->entries);
        self::assertNull($page->nextCursor);
    }
}
