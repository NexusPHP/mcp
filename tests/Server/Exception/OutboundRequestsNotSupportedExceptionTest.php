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
use Nexus\Mcp\Server\Exception\OutboundRequestsNotSupportedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OutboundRequestsNotSupportedException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class OutboundRequestsNotSupportedExceptionTest extends TestCase
{
    public function testComposesFixedMessage(): void
    {
        $e = new OutboundRequestsNotSupportedException();

        self::assertNull($e->requestId);
        self::assertSame(
            'Outbound server-to-client requests are not implemented yet.',
            $e->getMessage(),
        );
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new OutboundRequestsNotSupportedException(new RequestId(42), $previous);

        self::assertSame(42, $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
    }

    public function testReportsInternalErrorErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::InternalError, OutboundRequestsNotSupportedException::errorCode());
    }
}
