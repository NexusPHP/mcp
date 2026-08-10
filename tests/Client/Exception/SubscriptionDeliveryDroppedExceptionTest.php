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

use Nexus\Mcp\Client\Exception\SubscriptionDeliveryDroppedException;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(SubscriptionDeliveryDroppedException::class)]
#[Group('unit-tests')]
#[Group('client-tests')]
final class SubscriptionDeliveryDroppedExceptionTest extends AbstractMcpTestCase
{
    public function testRendersTheSubscriptionIdIntoMessage(): void
    {
        $e = new SubscriptionDeliveryDroppedException(new RequestId(7));

        self::assertSame('Subscription 7 was ended because a delivery was shed at the client\'s in-flight dispatch cap.', $e->getMessage());
        self::assertSame(7, $e->subscriptionId->id);
    }

    public function testAStringIdIsRenderedAsAQuotedString(): void
    {
        $e = new SubscriptionDeliveryDroppedException(new RequestId('feed-1'));

        self::assertSame('Subscription \'feed-1\' was ended because a delivery was shed at the client\'s in-flight dispatch cap.', $e->getMessage());
    }
}
