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

namespace Nexus\Mcp\Tests\Server\Exception;

use Nexus\Mcp\Server\Exception\DuplicateDiscoveredEntryException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(DuplicateDiscoveredEntryException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class DuplicateDiscoveredEntryExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesBothDeclaringSources(): void
    {
        self::assertSame(
            \sprintf('"%s" declares tool "search", which "%s" already declares.', self::class, parent::class),
            (new DuplicateDiscoveredEntryException('tool', 'search', self::class, parent::class))->getMessage(),
        );
    }
}
