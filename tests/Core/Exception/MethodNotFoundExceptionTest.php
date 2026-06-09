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
    public function testComposesMessageFromMethod(): void
    {
        $e = new MethodNotFoundException('vendor/whatever');

        self::assertNull($e->requestId);
        self::assertSame('No registration found for method "vendor/whatever".', $e->getMessage());
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new MethodNotFoundException('vendor/whatever', new RequestId(id: 99), $previous);

        self::assertSame(99, $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
        self::assertStringContainsString('"vendor/whatever"', $e->getMessage());
    }

    public function testReportsMethodNotFoundErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::MethodNotFound, MethodNotFoundException::getErrorCode());
    }

    public function testSanitisesAttackerControlledMethodBytesInTheMessage(): void
    {
        $e = new MethodNotFoundException("tools/list\n[CRIT] fake-log-line");

        self::assertSame(
            'No registration found for method "tools/list\\x0a[CRIT] fake-log-line".',
            $e->getMessage(),
        );
    }
}
