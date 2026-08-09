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

use Nexus\Mcp\Core\Dispatch\PendingOutboundRequests;
use Nexus\Mcp\Core\Exception\DuplicateOutboundRequestIdException;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result\EmptyResult;
use Nexus\Mcp\Core\Schema\ResultResponse\GenericResultResponse;
use Nexus\Mcp\Core\Transport\SendContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(PendingOutboundRequests::class)]
#[Group('unit-tests')]
#[Group('core-tests')]
final class PendingOutboundRequestsTest extends AbstractMcpTestCase
{
    public function testRegisterReturnsAFutureAndIncrementsTheCount(): void
    {
        $pending = new PendingOutboundRequests();
        $future = $pending->register(new RequestId(id: 1), GenericResultResponse::class);

        self::assertFalse($future->isComplete());
        self::assertCount(1, $pending);
    }

    public function testDuplicateRegisterThrowsDuplicateOutboundRequestIdException(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class);

        $this->expectException(DuplicateOutboundRequestIdException::class);
        $this->expectExceptionMessageMatches('/^Outbound request id 1 is already pending\./');

        $pending->register(new RequestId(id: 1), GenericResultResponse::class);
    }

    public function testDuplicateRegisterDoesNotMutateTheMap(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class);

        try {
            $pending->register(new RequestId(id: 1), GenericResultResponse::class);
        } catch (DuplicateOutboundRequestIdException) {
        }

        self::assertCount(1, $pending, 'A rejected register must not double-count the id.');
    }

    public function testRegisterStringIdEmitsTheValueInTheException(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 'corr-token'), GenericResultResponse::class);

        $this->expectException(DuplicateOutboundRequestIdException::class);
        $this->expectExceptionMessageMatches('/^Outbound request id \'corr-token\' is already pending\\./');

        $pending->register(new RequestId(id: 'corr-token'), GenericResultResponse::class);
    }

    public function testRegisterIntAndStringIdsAreDistinct(): void
    {
        $pending = new PendingOutboundRequests();

        $pending->register(new RequestId(id: 1), GenericResultResponse::class);
        $pending->register(new RequestId(id: '1'), GenericResultResponse::class);

        self::assertCount(2, $pending);
    }

    public function testResolveCompletesTheFutureWithTheGivenResponse(): void
    {
        $pending = new PendingOutboundRequests();
        $future = $pending->register(new RequestId(id: 7), GenericResultResponse::class);
        $response = new GenericResultResponse(id: new RequestId(id: 7), result: new EmptyResult());

        self::assertTrue($pending->resolve(new RequestId(id: 7), $response));
        self::assertTrue($future->isComplete());
        self::assertSame($response, $future->await());
    }

    public function testResolveRemovesTheEntry(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 7), GenericResultResponse::class);

        $pending->resolve(new RequestId(id: 7), new GenericResultResponse(id: new RequestId(id: 7), result: new EmptyResult()));

        self::assertCount(0, $pending);
        self::assertFalse(
            $pending->resolve(new RequestId(id: 7), new GenericResultResponse(id: new RequestId(id: 7), result: new EmptyResult())),
            'A second resolve for the same id must report no entry.',
        );
    }

    public function testResolveOnUnknownIdReturnsFalseAndDoesNotMutateOtherEntries(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class);

        $orphan = new GenericResultResponse(id: new RequestId(id: 999), result: new EmptyResult());
        self::assertFalse($pending->resolve(new RequestId(id: 999), $orphan));
        self::assertCount(1, $pending, 'Orphan resolve must leave registered entries alone.');
    }

    public function testRejectFailsTheFutureWithTheGivenError(): void
    {
        $pending = new PendingOutboundRequests();
        $future = $pending->register(new RequestId(id: 3), GenericResultResponse::class);
        $error = new \RuntimeException('peer rejected the call');

        self::assertTrue($pending->reject(new RequestId(id: 3), $error));
        self::assertTrue($future->isComplete());

        try {
            $future->await();
            self::fail('Future should have thrown the registered error.');
        } catch (\RuntimeException $thrown) {
            self::assertSame($error, $thrown);
        }
    }

    public function testRejectRemovesTheEntry(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 3), GenericResultResponse::class)->ignore();

        $pending->reject(new RequestId(id: 3), new \RuntimeException('boom'));

        self::assertCount(0, $pending);
        self::assertFalse(
            $pending->reject(new RequestId(id: 3), new \RuntimeException('again')),
            'A second reject for the same id must report no entry.',
        );
    }

    public function testRejectOnUnknownIdReturnsFalseAndDoesNotMutateOtherEntries(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class);

        self::assertFalse($pending->reject(new RequestId(id: 999), new \RuntimeException('orphan')));
        self::assertCount(1, $pending, 'Orphan reject must leave registered entries alone.');
    }

    public function testForgetRemovesTheEntryWithoutCompletingItsFuture(): void
    {
        $pending = new PendingOutboundRequests();
        $future = $pending->register(new RequestId(id: 3), GenericResultResponse::class);

        self::assertTrue($pending->forget(new RequestId(id: 3)));
        self::assertCount(0, $pending);
        self::assertFalse($future->isComplete());
    }

    public function testForgetOnUnknownIdReturnsFalseAndDoesNotMutateOtherEntries(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class);

        self::assertFalse($pending->forget(new RequestId(id: 999)));
        self::assertCount(1, $pending, 'Orphan forget must leave registered entries alone.');
    }

    public function testCancelAllFailsEveryPendingFutureWithTheGivenError(): void
    {
        $pending = new PendingOutboundRequests();
        $a = $pending->register(new RequestId(id: 1), GenericResultResponse::class);
        $b = $pending->register(new RequestId(id: 2), GenericResultResponse::class);
        $c = $pending->register(new RequestId(id: 'x'), GenericResultResponse::class);
        $error = new \RuntimeException('transport closed');

        $pending->cancelAll($error);

        foreach ([$a, $b, $c] as $future) {
            self::assertTrue($future->isComplete());

            try {
                $future->await();
                self::fail('Cancelled future should have thrown.');
            } catch (\RuntimeException $thrown) {
                self::assertSame($error, $thrown);
            }
        }
    }

    public function testCancelAllEmptiesTheMap(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class)->ignore();
        $pending->register(new RequestId(id: 2), GenericResultResponse::class)->ignore();

        $pending->cancelAll(new \RuntimeException('transport closed'));

        self::assertCount(0, $pending);
    }

    public function testCancelAllWithNothingPendingIsANoOp(): void
    {
        $pending = new PendingOutboundRequests();

        $pending->cancelAll(new \RuntimeException('nothing to cancel'));

        self::assertCount(0, $pending);
    }

    public function testAfterCancelAllANewRegisterReturnsAFreshFuture(): void
    {
        $pending = new PendingOutboundRequests();
        $original = $pending->register(new RequestId(id: 1), GenericResultResponse::class);
        $original->ignore();
        $pending->cancelAll(new \RuntimeException('first transport closed'));

        $reborn = $pending->register(new RequestId(id: 1), GenericResultResponse::class);

        self::assertNotSame($original, $reborn, 'The new register must yield a distinct future.');
        self::assertFalse($reborn->isComplete());
        self::assertCount(1, $pending);
    }

    public function testResultClassForReturnsTheRegisteredClass(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class);

        self::assertSame(GenericResultResponse::class, $pending->resolveResponseClass(new RequestId(id: 1)));
    }

    public function testResultClassForOnUnknownIdReturnsNull(): void
    {
        $pending = new PendingOutboundRequests();

        self::assertNull($pending->resolveResponseClass(new RequestId(id: 'never-registered')));
    }

    public function testResultClassForReturnsNullAfterResolve(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class);

        $pending->resolve(new RequestId(id: 1), new GenericResultResponse(id: new RequestId(id: 1), result: new EmptyResult()));

        self::assertNull($pending->resolveResponseClass(new RequestId(id: 1)), 'A resolved entry leaves no class lookup behind.');
    }

    public function testResultClassForReturnsNullAfterReject(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class)->ignore();

        $pending->reject(new RequestId(id: 1), new \RuntimeException('boom'));

        self::assertNull($pending->resolveResponseClass(new RequestId(id: 1)), 'A rejected entry leaves no class lookup behind.');
    }

    public function testResultClassForReturnsNullAfterCancelAll(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class)->ignore();
        $pending->register(new RequestId(id: 2), GenericResultResponse::class)->ignore();

        $pending->cancelAll(new \RuntimeException('transport closed'));

        self::assertNull($pending->resolveResponseClass(new RequestId(id: 1)));
        self::assertNull($pending->resolveResponseClass(new RequestId(id: 2)));
    }

    public function testAFreshMapRetainsNothing(): void
    {
        self::assertSame([], (new PendingOutboundRequests())->collectRetained());
    }

    public function testAnEntryRegisteredWithoutARequestRetainsNothing(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class)->ignore();

        self::assertSame([], $pending->collectRetained());
    }

    public function testARetainedEntryCarriesItsRequestAndContext(): void
    {
        $pending = new PendingOutboundRequests();
        $request = new TestRequest(new RequestId(id: 1));
        $context = new SendContext(relatedRequestId: new RequestId(id: 9));
        $pending->register(new RequestId(id: 1), GenericResultResponse::class, $request, $context)->ignore();

        self::assertSame([['request' => $request, 'context' => $context]], $pending->collectRetained());
    }

    public function testRetainedEntriesComeBackInRegistrationOrder(): void
    {
        $pending = new PendingOutboundRequests();
        $first = new TestRequest(new RequestId(id: 1));
        $second = new TestRequest(new RequestId(id: 2));
        $pending->register(new RequestId(id: 1), GenericResultResponse::class, $first)->ignore();
        $pending->register(new RequestId(id: 2), GenericResultResponse::class)->ignore();
        $pending->register(new RequestId(id: 3), GenericResultResponse::class, $second)->ignore();

        self::assertSame(
            [['request' => $first, 'context' => null], ['request' => $second, 'context' => null]],
            $pending->collectRetained(),
        );
    }

    public function testCancelUnretainedFailsOnlyTheEntriesCarryingNoRequest(): void
    {
        $pending = new PendingOutboundRequests();
        $retained = $pending->register(new RequestId(id: 1), GenericResultResponse::class, new TestRequest(new RequestId(id: 1)));
        $dropped = $pending->register(new RequestId(id: 2), GenericResultResponse::class);

        $pending->cancelUnretained(new \RuntimeException('transport closed'));

        self::assertFalse($retained->isComplete(), 'A retained entry outlives the peer that was carrying it.');
        self::assertSame(GenericResultResponse::class, $pending->resolveResponseClass(new RequestId(id: 1)));
        self::assertCount(1, $pending);

        try {
            $dropped->await();
            self::fail('Expected the unretained entry to be failed.');
        } catch (\RuntimeException $e) {
            self::assertSame('transport closed', $e->getMessage());
        }
    }

    public function testCancelUnretainedOnAMapOfOnlyRetainedEntriesFailsNothing(): void
    {
        $pending = new PendingOutboundRequests();
        $future = $pending->register(new RequestId(id: 1), GenericResultResponse::class, new TestRequest(new RequestId(id: 1)));

        $pending->cancelUnretained(new \RuntimeException('transport closed'));

        self::assertFalse($future->isComplete());
        self::assertCount(1, $pending);
    }

    public function testResolvingARetainedEntryDropsItsRetention(): void
    {
        $pending = new PendingOutboundRequests();
        $pending->register(new RequestId(id: 1), GenericResultResponse::class, new TestRequest(new RequestId(id: 1)))->ignore();

        $pending->resolve(new RequestId(id: 1), new GenericResultResponse(id: new RequestId(id: 1), result: new EmptyResult()));

        self::assertSame([], $pending->collectRetained(), 'An answered request is not one to send again.');
    }
}
