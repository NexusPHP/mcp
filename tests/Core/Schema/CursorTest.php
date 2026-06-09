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

namespace Nexus\Mcp\Tests\Core\Schema;

use Nexus\Assert\ExpectationFailedException;
use Nexus\Mcp\Core\Schema\Cursor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Cursor::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class CursorTest extends TestCase
{
    public function testCursorAcceptsNonEmptyString(): void
    {
        self::assertSame('abc123', new Cursor(cursor: 'abc123')->cursor);
    }

    public function testCursorRejectsEmptyString(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessageIs('"cursor" must be a non-empty string.');

        new Cursor(cursor: '');
    }
}
