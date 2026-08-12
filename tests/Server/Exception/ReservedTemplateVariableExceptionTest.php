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

use Nexus\Mcp\Server\Exception\ReservedTemplateVariableException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ReservedTemplateVariableException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ReservedTemplateVariableExceptionTest extends AbstractMcpTestCase
{
    public function testMessageNamesTheDeclaringMethod(): void
    {
        self::assertSame(
            \sprintf('%s::doc() declares template variable "{uri}", which is reserved for the injected request URI. Rename the variable.', self::class),
            (new ReservedTemplateVariableException(self::class, 'doc'))->getMessage(),
        );
    }
}
