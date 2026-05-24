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

namespace Nexus\Mcp\Tests\Core\Dispatch;

use Nexus\Mcp\Core\Dispatch\PendingInboundRequests;
use Nexus\Mcp\Core\Schema\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PendingInboundRequests::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PendingInboundRequestsTest extends TestCase
{
    public function testFirstClaimSucceedsAndRegistersTheId(): void
    {
        $set = new PendingInboundRequests();
        $id = new RequestId(1);

        self::assertTrue($set->claim($id));
        self::assertCount(1, $set);
    }

    public function testDuplicateClaimFailsWithoutMutatingTheSet(): void
    {
        $set = new PendingInboundRequests();
        $id = new RequestId(1);

        self::assertTrue($set->claim($id));
        self::assertFalse($set->claim($id));
        self::assertCount(1, $set, 'A rejected claim must not double-count the id.');
    }

    public function testDuplicateStringClaimFailsWithoutMutatingTheSet(): void
    {
        $set = new PendingInboundRequests();
        $id = new RequestId('correlation-token');

        self::assertTrue($set->claim($id));
        self::assertFalse($set->claim($id), 'String ids must collide on a duplicate claim, just like int ids.');
        self::assertCount(1, $set);
    }

    public function testClaimComparesByEnvelopeIdValueNotInstanceIdentity(): void
    {
        $set = new PendingInboundRequests();

        self::assertTrue($set->claim(new RequestId(7)));
        self::assertFalse($set->claim(new RequestId(7)), 'Distinct RequestId instances with the same envelope id must collide.');
    }

    public function testIntAndStringIdsAreDistinct(): void
    {
        $set = new PendingInboundRequests();

        self::assertTrue($set->claim(new RequestId(1)));
        self::assertTrue($set->claim(new RequestId('1')), 'String "1" and int 1 are distinct envelope ids per JSON-RPC.');
        self::assertCount(2, $set);
    }

    public function testDistinctIdsOfTheSameTypeAreTrackedIndependently(): void
    {
        $set = new PendingInboundRequests();

        self::assertTrue($set->claim(new RequestId(1)));
        self::assertTrue($set->claim(new RequestId(2)));
        self::assertTrue($set->claim(new RequestId('a')));
        self::assertTrue($set->claim(new RequestId('b')));
        self::assertCount(4, $set);
    }

    public function testReleasingAStringIdLeavesUnrelatedIntIdInPlace(): void
    {
        $set = new PendingInboundRequests();
        $intId = new RequestId(1);
        $stringId = new RequestId('1');

        $set->claim($intId);
        $set->claim($stringId);
        $set->release($stringId);

        self::assertCount(1, $set);
        self::assertFalse($set->claim($intId), 'Releasing a string id must not touch a same-spelt int id.');
        self::assertTrue($set->claim($stringId), 'The released string id is gone, so it is reclaimable.');
    }

    public function testReleaseAllowsReclaiming(): void
    {
        $set = new PendingInboundRequests();
        $id = new RequestId(1);

        $set->claim($id);
        $set->release($id);

        self::assertCount(0, $set);
        self::assertTrue($set->claim($id), 'A released id must be reclaimable.');
    }

    public function testReleaseOfUnknownIdIsNoOp(): void
    {
        $set = new PendingInboundRequests();

        $set->release(new RequestId('never-claimed'));

        self::assertCount(0, $set);
    }
}
