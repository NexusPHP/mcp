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

namespace Nexus\Mcp\Tests\Core\Http;

use Nexus\Mcp\Core\Http\ParameterHeaderBinding;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ParameterHeaderBinding::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ParameterHeaderBindingTest extends AbstractMcpTestCase
{
    public function testExposesItsFields(): void
    {
        $binding = new ParameterHeaderBinding(['a', 'b'], 'Region', 'string');

        self::assertSame(['a', 'b'], $binding->path);
        self::assertSame('Region', $binding->headerName);
        self::assertSame('string', $binding->type);
    }
}
