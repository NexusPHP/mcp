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
use Nexus\Mcp\Core\Http\ParameterHeaderScanResult;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ParameterHeaderScanResult::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ParameterHeaderScanResultTest extends AbstractMcpTestCase
{
    public function testValidCarriesBindingsAndNoReason(): void
    {
        $binding = new ParameterHeaderBinding(['region'], 'Region', 'string');
        $result = ParameterHeaderScanResult::valid([$binding]);

        self::assertTrue($result->valid);
        self::assertSame([$binding], $result->bindings);
        self::assertNull($result->reason);
    }

    public function testInvalidCarriesReasonAndNoBindings(): void
    {
        $result = ParameterHeaderScanResult::invalid('bad schema');

        self::assertFalse($result->valid);
        self::assertSame([], $result->bindings);
        self::assertSame('bad schema', $result->reason);
    }
}
