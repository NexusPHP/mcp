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

use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Exception\InvalidRequestException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InvalidRequestException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class InvalidRequestExceptionTest extends TestCase
{
    public function testReportsInvalidRequestErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::InvalidRequest, InvalidRequestException::errorCode());
    }
}
