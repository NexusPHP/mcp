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
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(PendingInboundRequests::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PendingInboundRequestsTest extends AbstractMcpTestCase
{
    public function testFirstClaimSucceedsAndRegistersTheId(): void
    {
        $set = new PendingInboundRequests();
        $id = new RequestId(id: 1);

        self::assertNotNull($set->claim($id));
        self::assertCount(1, $set);
    }

    public function testDuplicateClaimFailsWithoutMutatingTheSet(): void
    {
        $set = new PendingInboundRequests();
        $id = new RequestId(id: 1);

        self::assertNotNull($set->claim($id));
        self::assertNull($set->claim($id));
        self::assertCount(1, $set, 'A rejected claim must not double-count the id.');
    }

    public function testDuplicateStringClaimFailsWithoutMutatingTheSet(): void
    {
        $set = new PendingInboundRequests();
        $id = new RequestId(id: 'correlation-token');

        self::assertNotNull($set->claim($id));
        self::assertNull($set->claim($id), 'String ids must collide on a duplicate claim, just like int ids.');
        self::assertCount(1, $set);
    }

    public function testClaimComparesByEnvelopeIdValueNotInstanceIdentity(): void
    {
        $set = new PendingInboundRequests();

        self::assertNotNull($set->claim(new RequestId(id: 7)));
        self::assertNull($set->claim(new RequestId(id: 7)), 'Distinct RequestId instances with the same envelope id must collide.');
    }

    public function testIntAndStringIdsAreDistinct(): void
    {
        $set = new PendingInboundRequests();

        self::assertNotNull($set->claim(new RequestId(id: 1)));
        self::assertNotNull($set->claim(new RequestId(id: '1')), 'String "1" and int 1 are distinct envelope ids per JSON-RPC.');
        self::assertCount(2, $set);
    }

    public function testDistinctIdsOfTheSameTypeAreTrackedIndependently(): void
    {
        $set = new PendingInboundRequests();

        self::assertNotNull($set->claim(new RequestId(id: 1)));
        self::assertNotNull($set->claim(new RequestId(id: 2)));
        self::assertNotNull($set->claim(new RequestId(id: 'a')));
        self::assertNotNull($set->claim(new RequestId(id: 'b')));
        self::assertCount(4, $set);
    }

    public function testReleasingAStringIdLeavesUnrelatedIntIdInPlace(): void
    {
        $set = new PendingInboundRequests();
        $intId = new RequestId(id: 1);
        $stringId = new RequestId(id: '1');

        $set->claim($intId);
        $set->claim($stringId);
        $set->release($stringId);

        self::assertCount(1, $set);
        self::assertNull($set->claim($intId), 'Releasing a string id must not touch a same-spelt int id.');
        self::assertNotNull($set->claim($stringId), 'The released string id is gone, so it is reclaimable.');
    }

    public function testReleaseAllowsReclaiming(): void
    {
        $set = new PendingInboundRequests();
        $id = new RequestId(id: 1);

        $set->claim($id);
        $set->release($id);

        self::assertCount(0, $set);
        self::assertNotNull($set->claim($id), 'A released id must be reclaimable.');
    }

    public function testReleaseOfUnknownIdIsNoOp(): void
    {
        $set = new PendingInboundRequests();

        $set->release(new RequestId(id: 'never-claimed'));

        self::assertCount(0, $set);
    }

    public function testAClaimedRequestStartsUncancelled(): void
    {
        $set = new PendingInboundRequests();

        $cancellation = $set->claim(new RequestId(id: 1));

        // The deferred source must outlive the claim: its destructor cancels, so a map that held only
        // the derived token would hand back an already-cancelled one.
        self::assertNotNull($cancellation);
        self::assertFalse($cancellation->isRequested());
    }

    public function testCancelRequestsTheCancellationTheClaimHandedOut(): void
    {
        $set = new PendingInboundRequests();
        $id = new RequestId(id: 1);
        $cancellation = $set->claim($id);

        self::assertTrue($set->cancel($id));
        self::assertNotNull($cancellation);
        self::assertTrue($cancellation->isRequested());
    }

    public function testCancelReportsNothingInFlightForAnUnknownId(): void
    {
        $set = new PendingInboundRequests();

        self::assertFalse($set->cancel(new RequestId(id: 'never-claimed')));
    }

    public function testCancelReportsNothingInFlightOnceTheRequestWasReleased(): void
    {
        $set = new PendingInboundRequests();
        $id = new RequestId(id: 1);
        $set->claim($id);
        $set->release($id);

        self::assertFalse($set->cancel($id));
    }

    public function testCancellingTwiceIsHarmless(): void
    {
        $set = new PendingInboundRequests();
        $id = new RequestId(id: 1);
        $cancellation = $set->claim($id);

        self::assertTrue($set->cancel($id));
        self::assertTrue($set->cancel($id));
        self::assertNotNull($cancellation);
        self::assertTrue($cancellation->isRequested());
    }

    public function testCancellingOneRequestLeavesTheOthersRunning(): void
    {
        $set = new PendingInboundRequests();
        $first = new RequestId(id: 1);
        $second = new RequestId(id: 2);
        $firstCancellation = $set->claim($first);
        $secondCancellation = $set->claim($second);

        $set->cancel($first);

        self::assertNotNull($firstCancellation);
        self::assertNotNull($secondCancellation);
        self::assertTrue($firstCancellation->isRequested());
        self::assertFalse($secondCancellation->isRequested());
    }
}
