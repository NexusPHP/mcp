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
use Nexus\Mcp\Server\Exception\SubscriptionLimitReachedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SubscriptionLimitReachedException::class)]
#[CoversClass(AbstractJsonRpcProtocolException::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class SubscriptionLimitReachedExceptionTest extends TestCase
{
    public function testMessageNamesTheLimitItRefusedAt(): void
    {
        $exception = new SubscriptionLimitReachedException(1024, new RequestId(id: 7));

        self::assertSame('Subscription limit reached: this server holds at most 1024 open streams.', $exception->getMessage());
        self::assertSame(1024, $exception->limit);
        self::assertSame(7, $exception->requestId?->id);
    }

    public function testCarriesTheLimitAsErrorData(): void
    {
        self::assertSame(['limit' => 4], new SubscriptionLimitReachedException(4)->errorData);
    }

    public function testUsesTheInternalErrorCode(): void
    {
        self::assertSame(ProtocolErrorCode::InternalError, SubscriptionLimitReachedException::getErrorCode());
    }
}
