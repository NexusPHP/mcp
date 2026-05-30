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

use Nexus\Mcp\Server\Exception\UnsupportedParameterTypeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UnsupportedParameterTypeException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class UnsupportedParameterTypeExceptionTest extends TestCase
{
    public function testMessageNamesTheOffendingMethodParameterAndType(): void
    {
        self::assertSame(
            \sprintf('%s::rank() declares parameter "$level" of unsupported type "int". It is bound from a string value.', self::class),
            new UnsupportedParameterTypeException(self::class, 'rank', 'level', 'int')->getMessage(),
        );
    }
}
