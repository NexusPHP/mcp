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
use Nexus\Mcp\Core\Exception\MethodMisroutedException;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(MethodMisroutedException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class MethodMisroutedExceptionTest extends TestCase
{
    public function testComposesMessageFromMethodAndShapes(): void
    {
        $e = new MethodMisroutedException(
            'initialize',
            expectedShape: 'request',
            receivedShape: 'notification',
        );

        self::assertNull($e->requestId);
        self::assertSame('Method "initialize" must be sent as a request, not a notification.', $e->getMessage());
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new MethodMisroutedException(
            'notifications/initialized',
            expectedShape: 'notification',
            receivedShape: 'request',
            requestId: new RequestId(id: 42),
            previous: $previous,
        );

        self::assertSame(42, $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
        self::assertSame(
            'Method "notifications/initialized" must be sent as a notification, not a request.',
            $e->getMessage(),
        );
    }

    public function testReportsInvalidRequestErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::InvalidRequest, MethodMisroutedException::getErrorCode());
    }

    public function testSanitisesAttackerControlledMethodBytesInTheMessage(): void
    {
        $e = new MethodMisroutedException(
            "initialize\x1b[31mEVIL",
            expectedShape: 'request',
            receivedShape: 'notification',
        );

        self::assertSame(
            'Method "initialize\\x1b[31mEVIL" must be sent as a request, not a notification.',
            $e->getMessage(),
        );
    }
}
