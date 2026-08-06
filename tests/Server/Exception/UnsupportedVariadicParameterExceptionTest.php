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

use Nexus\Mcp\Server\Exception\UnsupportedVariadicParameterException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(UnsupportedVariadicParameterException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class UnsupportedVariadicParameterExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesTheOffendingMethodAndParameter(): void
    {
        self::assertSame(
            \sprintf('%s::handle() declares a variadic parameter "$items". Variadic parameters are supported only on #[AsTool] methods.', self::class),
            (new UnsupportedVariadicParameterException(self::class, 'handle', 'items'))->getMessage(),
        );
    }
}
