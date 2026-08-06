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
use Nexus\Mcp\Server\Exception\ToolNotFoundException;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ToolNotFoundException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ToolNotFoundExceptionTest extends AbstractMcpTestCase
{
    public function testCarriesNameAndComposesMessage(): void
    {
        $e = new ToolNotFoundException('missing');

        self::assertSame('missing', $e->name);
        self::assertNull($e->requestId);
        self::assertSame('No tool registered under name "missing".', $e->getMessage());
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new ToolNotFoundException('missing', new RequestId(id: 42), $previous);

        self::assertSame('missing', $e->name);
        self::assertSame(42, $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
    }

    public function testReportsInvalidParamsErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::InvalidParams, ToolNotFoundException::getErrorCode());
    }
}
