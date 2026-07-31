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

namespace Nexus\Mcp\Tests\Server\Subscription;

use Amp\DeferredFuture;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\SubscriptionFilter;
use Nexus\Mcp\Server\Subscription\SubscriptionEntry;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SubscriptionEntry::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class SubscriptionEntryTest extends TestCase
{
    public function testCarriesTheStreamIdentityAndItsPump(): void
    {
        $id = new RequestId(id: 1);
        $honoured = new SubscriptionFilter(toolsListChanged: true);
        $sender = new RecordingSender();

        /** @var DeferredFuture<null> $closed */
        $closed = new DeferredFuture();
        $entry = new SubscriptionEntry($id, $honoured, $sender, $closed);

        self::assertSame($id, $entry->subscriptionId);
        self::assertSame($honoured, $entry->honoured);
        self::assertSame($sender, $entry->sender);
        self::assertSame($closed, $entry->closed);
    }
}
