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

use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Server\Exception\InvalidCursorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InvalidCursorException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class InvalidCursorExceptionTest extends TestCase
{
    public function testComposesMessageFromCursor(): void
    {
        $e = new InvalidCursorException('opaque-token');

        self::assertNull($e->requestId);
        self::assertSame('Cursor "opaque-token" does not match any registered entry.', $e->getMessage());
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new InvalidCursorException('opaque-token', new RequestId(7), $previous);

        self::assertSame(7, $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
    }

    public function testReportsInvalidParamsErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::InvalidParams, InvalidCursorException::errorCode());
    }
}
