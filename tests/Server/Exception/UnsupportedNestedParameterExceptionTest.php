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

use Nexus\Mcp\Server\Exception\UnsupportedNestedParameterException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(UnsupportedNestedParameterException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class UnsupportedNestedParameterExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesTheClassParameterAndType(): void
    {
        self::assertSame(
            \sprintf(
                '%s declares constructor parameter "$origin" of type "%s", which the binder cannot construct from a value map. Nested object expansion is not supported.',
                self::class,
                \stdClass::class,
            ),
            (new UnsupportedNestedParameterException(self::class, 'origin', \stdClass::class))->getMessage(),
        );
    }
}
