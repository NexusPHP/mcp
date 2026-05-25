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

use Nexus\Mcp\Server\Exception\DuplicateServerMetadataException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DuplicateServerMetadataException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class DuplicateServerMetadataExceptionTest extends TestCase
{
    public function testMessageNamesTheOffendingSource(): void
    {
        self::assertSame(
            \sprintf('A class-level #[AsServer] is already declared by an earlier registered source. "%s" must not declare another.', self::class),
            new DuplicateServerMetadataException(self::class)->getMessage(),
        );
    }
}
