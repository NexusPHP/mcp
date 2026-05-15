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
use Nexus\Mcp\Server\Exception\ResourceNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ResourceNotFoundException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ResourceNotFoundExceptionTest extends TestCase
{
    public function testCarriesUriAndComposesMessage(): void
    {
        $e = new ResourceNotFoundException('file:///missing');

        self::assertSame('file:///missing', $e->uri);
        self::assertNull($e->requestId);
        self::assertSame('No resource registered under URI "file:///missing".', $e->getMessage());
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new ResourceNotFoundException('file:///missing', new RequestId(42), $previous);

        self::assertSame('file:///missing', $e->uri);
        self::assertSame(42, $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
    }

    public function testReportsInvalidParamsErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::InvalidParams, ResourceNotFoundException::errorCode());
    }
}
