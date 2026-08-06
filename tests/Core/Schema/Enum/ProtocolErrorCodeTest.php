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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ProtocolErrorCode::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class ProtocolErrorCodeTest extends AbstractMcpTestCase
{
    #[DataProvider('provideProtocolErrorCodeCaseValueCases')]
    public function testProtocolErrorCodeCaseValue(ProtocolErrorCode $case, int $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * @return iterable<string, array{ProtocolErrorCode, int}>
     */
    public static function provideProtocolErrorCodeCaseValueCases(): iterable
    {
        yield 'Parse error' => [ProtocolErrorCode::ParseError, -32_700];

        yield 'Invalid request' => [ProtocolErrorCode::InvalidRequest, -32_600];

        yield 'Method not found' => [ProtocolErrorCode::MethodNotFound, -32_601];

        yield 'Invalid params' => [ProtocolErrorCode::InvalidParams, -32_602];

        yield 'Internal error' => [ProtocolErrorCode::InternalError, -32_603];
    }

    public function testProtocolErrorCodeFollowsJsonRpcSpecification(): void
    {
        foreach (ProtocolErrorCode::cases() as $case) {
            self::assertGreaterThanOrEqual(-32_768, $case->value);
            self::assertLessThanOrEqual(-32_000, $case->value);
        }
    }
}
