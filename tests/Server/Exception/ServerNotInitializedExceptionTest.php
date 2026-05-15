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
use Nexus\Mcp\Server\Exception\ServerNotInitializedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ServerNotInitializedException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class ServerNotInitializedExceptionTest extends TestCase
{
    public function testComposesMessageFromMethod(): void
    {
        $e = new ServerNotInitializedException('tools/list');

        self::assertNull($e->requestId);
        self::assertSame(
            'Cannot handle "tools/list" before the client has sent "notifications/initialized".',
            $e->getMessage(),
        );
    }

    public function testCarriesProvidedRequestIdAndPrevious(): void
    {
        $previous = new \RuntimeException('inner');
        $e = new ServerNotInitializedException('prompts/get', new RequestId('req-1'), $previous);

        self::assertSame('req-1', $e->requestId?->id);
        self::assertSame($previous, $e->getPrevious());
        self::assertStringContainsString('"prompts/get"', $e->getMessage());
    }

    public function testReportsInvalidRequestErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::InvalidRequest, ServerNotInitializedException::errorCode());
    }
}
