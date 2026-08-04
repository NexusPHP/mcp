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

namespace Nexus\Mcp\Tests\Core\Exception;

use Nexus\Mcp\Core\Exception\DuplicateExtensionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DuplicateExtensionException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class DuplicateExtensionExceptionTest extends TestCase
{
    public function testMessageNamesTheIdentifier(): void
    {
        self::assertSame(
            'Extension "com.example/feature" is declared more than once.',
            new DuplicateExtensionException('com.example/feature')->getMessage(),
        );
    }
}
