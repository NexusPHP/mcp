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
use Nexus\Mcp\Server\Exception\DuplicateInFlightRequestIdException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DuplicateInFlightRequestIdException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class DuplicateInFlightRequestIdExceptionTest extends TestCase
{
    public function testComposesFixedMessage(): void
    {
        $e = new DuplicateInFlightRequestIdException(new RequestId(42));

        self::assertSame(42, $e->requestId?->id);
        self::assertSame(
            'Request id is already in flight on this session.',
            $e->getMessage(),
        );
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new DuplicateInFlightRequestIdException(new RequestId('abc'), $previous);

        self::assertSame('abc', $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
    }

    public function testReportsInvalidRequestErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::InvalidRequest, DuplicateInFlightRequestIdException::errorCode());
    }
}
