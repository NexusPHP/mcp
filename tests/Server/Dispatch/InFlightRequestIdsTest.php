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

namespace Nexus\Mcp\Tests\Server\Dispatch;

use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Server\Dispatch\InFlightRequestIds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InFlightRequestIds::class)]
#[Group('unit-tests')]
#[Group('server-tests')]
final class InFlightRequestIdsTest extends TestCase
{
    public function testFirstClaimSucceedsAndRegistersTheId(): void
    {
        $set = new InFlightRequestIds();
        $id = new RequestId(1);

        self::assertTrue($set->tryClaim($id));
        self::assertTrue($set->contains($id));
        self::assertCount(1, $set);
    }

    public function testDuplicateClaimFailsWithoutMutatingTheSet(): void
    {
        $set = new InFlightRequestIds();
        $id = new RequestId(1);

        self::assertTrue($set->tryClaim($id));
        self::assertFalse($set->tryClaim($id));
        self::assertCount(1, $set, 'A rejected claim must not double-count the id.');
    }

    public function testDuplicateStringClaimFailsWithoutMutatingTheSet(): void
    {
        $set = new InFlightRequestIds();
        $id = new RequestId('correlation-token');

        self::assertTrue($set->tryClaim($id));
        self::assertFalse($set->tryClaim($id), 'String ids must collide on a duplicate claim, just like int ids.');
        self::assertCount(1, $set);
    }

    public function testClaimComparesByEnvelopeIdValueNotInstanceIdentity(): void
    {
        $set = new InFlightRequestIds();

        self::assertTrue($set->tryClaim(new RequestId(7)));
        self::assertFalse($set->tryClaim(new RequestId(7)), 'Distinct RequestId instances with the same envelope id must collide.');
    }

    public function testIntAndStringIdsAreDistinct(): void
    {
        $set = new InFlightRequestIds();

        self::assertTrue($set->tryClaim(new RequestId(1)));
        self::assertTrue($set->tryClaim(new RequestId('1')), 'String "1" and int 1 are distinct envelope ids per JSON-RPC.');
        self::assertCount(2, $set);
    }

    public function testDistinctIdsOfTheSameTypeAreTrackedIndependently(): void
    {
        $set = new InFlightRequestIds();

        self::assertTrue($set->tryClaim(new RequestId(1)));
        self::assertTrue($set->tryClaim(new RequestId(2)));
        self::assertTrue($set->tryClaim(new RequestId('a')));
        self::assertTrue($set->tryClaim(new RequestId('b')));
        self::assertCount(4, $set);
    }

    public function testReleasingAStringIdLeavesUnrelatedIntIdInPlace(): void
    {
        $set = new InFlightRequestIds();
        $intId = new RequestId(1);
        $stringId = new RequestId('1');

        $set->tryClaim($intId);
        $set->tryClaim($stringId);
        $set->release($stringId);

        self::assertTrue($set->contains($intId), 'Releasing a string id must not touch a same-spelt int id.');
        self::assertFalse($set->contains($stringId));
    }

    public function testReleaseAllowsReclaiming(): void
    {
        $set = new InFlightRequestIds();
        $id = new RequestId(1);

        $set->tryClaim($id);
        $set->release($id);

        self::assertFalse($set->contains($id));
        self::assertCount(0, $set);
        self::assertTrue($set->tryClaim($id), 'A released id must be reclaimable.');
    }

    public function testReleaseOfUnknownIdIsNoOp(): void
    {
        $set = new InFlightRequestIds();

        $set->release(new RequestId('never-claimed'));

        self::assertCount(0, $set);
    }
}
