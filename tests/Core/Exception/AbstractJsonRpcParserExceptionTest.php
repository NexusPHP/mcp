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

use Nexus\Mcp\Core\Exception\AbstractJsonRpcParserException;
use Nexus\Mcp\Tests\Fixtures\Core\Exception\StubParserException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AbstractJsonRpcParserException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class AbstractJsonRpcParserExceptionTest extends TestCase
{
    public function testCarriesMessageAndDefaultsRequestIdToNull(): void
    {
        $e = new StubParserException('boom');

        self::assertSame('boom', $e->getMessage());
        self::assertNull($e->requestId);
        self::assertNull($e->getPrevious());
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new StubParserException('boom', 'req-42', $previous);

        self::assertSame('boom', $e->getMessage());
        self::assertSame('req-42', $e->requestId);
        self::assertSame($previous, $e->getPrevious());
    }

    public function testAcceptsIntegerRequestId(): void
    {
        $e = new StubParserException('boom', 7);

        self::assertSame(7, $e->requestId);
    }
}
