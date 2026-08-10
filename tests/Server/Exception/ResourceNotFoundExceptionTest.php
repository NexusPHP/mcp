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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(ResourceNotFoundException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ResourceNotFoundExceptionTest extends AbstractMcpTestCase
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
        $e = new ResourceNotFoundException('file:///missing', new RequestId(id: 42), $previous);

        self::assertSame('file:///missing', $e->uri);
        self::assertSame(42, $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
    }

    public function testReportsInvalidParamsErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::InvalidParams, ResourceNotFoundException::getErrorCode());
    }

    public function testEchoesTheUriInTheErrorData(): void
    {
        $e = new ResourceNotFoundException('file:///missing');

        self::assertSame(['uri' => 'file:///missing'], $e->errorData);
    }

    public function testTheErrorDataKeepsTheUriVerbatimWhileTheMessageIsBounded(): void
    {
        $uri = \sprintf('file:///%s', str_repeat('a', 400));
        $e = new ResourceNotFoundException($uri);

        self::assertSame(['uri' => $uri], $e->errorData);
        self::assertSame(\sprintf('No resource registered under URI "file:///%s...".', str_repeat('a', 245)), $e->getMessage());
    }

    public function testBoundsAnOverlongValueInTheMessage(): void
    {
        $e = new ResourceNotFoundException(str_repeat('a', 200_000));

        self::assertSame(\sprintf('No resource registered under URI "%s...".', str_repeat('a', 253)), $e->getMessage());
    }

    public function testEscapesControlBytesInTheMessage(): void
    {
        $e = new ResourceNotFoundException("ev\x1b[2K\x07il");

        self::assertSame('No resource registered under URI "ev\\x1b[2K\\x07il".', $e->getMessage());
    }
}
