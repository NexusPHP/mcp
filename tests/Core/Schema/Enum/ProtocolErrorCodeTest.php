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

namespace Nexus\Mcp\Tests\Core\Schema\Enum;

use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProtocolErrorCode::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ProtocolErrorCodeTest extends TestCase
{
    #[DataProvider('provideProtocolErrorCodeCaseValueCases')]
    public function testProtocolErrorCodeCaseValue(ProtocolErrorCode $case, int $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<array-key, array{ProtocolErrorCode, int}>
     */
    public static function provideProtocolErrorCodeCaseValueCases(): iterable
    {
        yield 'Parse error' => [ProtocolErrorCode::ParseError, -32700];

        yield 'Invalid request' => [ProtocolErrorCode::InvalidRequest, -32600];

        yield 'Method not found' => [ProtocolErrorCode::MethodNotFound, -32601];

        yield 'Invalid params' => [ProtocolErrorCode::InvalidParams, -32602];

        yield 'Internal error' => [ProtocolErrorCode::InternalError, -32603];
    }

    public function testProtocolErrorCodeFollowsJsonRpcSpecification(): void
    {
        // JSON-RPC spec error codes are in range -32768 to -32000
        foreach (ProtocolErrorCode::cases() as $case) {
            self::assertGreaterThanOrEqual(-32768, $case->value);
            self::assertLessThanOrEqual(-32000, $case->value);
        }
    }
}
