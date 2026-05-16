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

use Nexus\Mcp\Core\Schema\Resource\Resource;
use Nexus\Mcp\Server\Resource\ClosureResourceReader;
use Nexus\Mcp\Server\Resource\ResourceEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResourceEntry::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ResourceEntryTest extends TestCase
{
    public function testExposesResourceAndReader(): void
    {
        $resource = new Resource('etc', 'file:///etc');
        $reader = new ClosureResourceReader(
            static fn(): never => throw new \LogicException('unreachable'),
        );

        $entry = new ResourceEntry($resource, $reader);

        self::assertSame($resource, $entry->resource);
        self::assertSame($reader, $entry->reader);
    }
}
