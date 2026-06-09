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
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Tests\Fixtures\Core\Exception\StubProtocolException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class AbstractJsonRpcProtocolExceptionTest extends TestCase
{
    public function testCarriesMessageAndDefaultsPreviousToNull(): void
    {
        $e = new StubProtocolException(null, 'boom');

        self::assertSame('boom', $e->getMessage());
        self::assertNull($e->requestId);
        self::assertNull($e->getPrevious());
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new StubProtocolException(new RequestId(id: 'req-42'), 'boom', $previous);

        self::assertSame('boom', $e->getMessage());
        self::assertSame('req-42', $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
    }

    public function testAcceptsIntegerRequestId(): void
    {
        $e = new StubProtocolException(new RequestId(id: 7), 'boom');

        self::assertSame(7, $e->requestId?->id);
    }
}
