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
use Nexus\Mcp\Core\Exception\MethodNotFoundException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MethodNotFoundException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MethodNotFoundExceptionTest extends TestCase
{
    public function testCarriesMethodAndComposesMessage(): void
    {
        $e = new MethodNotFoundException('vendor/whatever');

        self::assertSame('vendor/whatever', $e->method);
        self::assertNull($e->requestId);
        self::assertSame('No class registered for method "vendor/whatever".', $e->getMessage());
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new MethodNotFoundException('vendor/whatever', new RequestId(99), $previous);

        self::assertSame('vendor/whatever', $e->method);
        self::assertSame(99, $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
    }

    public function testReportsMethodNotFoundErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::MethodNotFound, MethodNotFoundException::errorCode());
    }
}
