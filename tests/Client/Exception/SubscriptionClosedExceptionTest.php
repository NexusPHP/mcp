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

namespace Nexus\Mcp\Tests\Client\Exception;

use Nexus\Mcp\Client\Exception\SubscriptionClosedException;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SubscriptionClosedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class SubscriptionClosedExceptionTest extends AbstractMcpTestCase
{
    public function testRendersTheSubscriptionIdIntoMessage(): void
    {
        $e = new SubscriptionClosedException(new RequestId(7));

        self::assertSame('Subscription 7 was closed by this client, so it carries no response to await.', $e->getMessage());
        self::assertSame(7, $e->subscriptionId->id);
        self::assertNull($e->getPrevious());
    }

    public function testAStringIdIsRenderedAsAQuotedString(): void
    {
        $e = new SubscriptionClosedException(new RequestId('feed-1'), new \RuntimeException('prior'));

        self::assertSame('Subscription \'feed-1\' was closed by this client, so it carries no response to await.', $e->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
    }
}
