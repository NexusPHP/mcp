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

namespace Nexus\Mcp\Tests\Extension\Tasks\Server\Store;

use Nexus\Mcp\Core\Exception\RuntimeException;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitResult;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\Enum\ElicitAction;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Server\Exception\InputRequestKeyReusedException;
use Nexus\Mcp\Extension\Tasks\Server\Store\InMemoryTaskStore;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskRecord;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Time\SettableClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(InMemoryTaskStore::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class InMemoryTaskStoreTest extends AbstractMcpTestCase
{
    private SettableClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = new SettableClock(new \DateTimeImmutable('2026-08-04T12:00:00+00:00'));
    }

    public function testCreateTaskSweepsExpiredTerminalRecords(): void
    {
        $store = $this->buildStore();
        $expired = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;
        $store->trySetCompleted($expired, ['resultType' => 'complete']);

        $this->clock->travel('+2 seconds');
        $store->createTask('slow_compute', null, 1_000, 1_000);

        $records = (new \ReflectionProperty(InMemoryTaskStore::class, 'records'))->getValue($store);
        $terminalAt = (new \ReflectionProperty(InMemoryTaskStore::class, 'terminalAt'))->getValue($store);
        self::assertIsArray($records);
        self::assertIsArray($terminalAt);
        self::assertArrayNotHasKey($expired, $records);
        self::assertArrayNotHasKey($expired, $terminalAt);
    }

    public function testCreateTaskIsDurableBeforeReturning(): void
    {
        $store = $this->buildStore();
        $record = $store->createTask('slow_compute', ['seconds' => 2], 300_000, 1_000);

        self::assertSame(TaskStatus::Working, $record->status);
        self::assertSame('slow_compute', $record->toolName);
        self::assertSame(['seconds' => 2], $record->arguments);
        self::assertSame('2026-08-04T12:00:00+00:00', $record->createdAt);
        self::assertSame('2026-08-04T12:00:00+00:00', $record->lastUpdatedAt);
        self::assertSame(300_000, $record->ttlMs);
        self::assertSame(1_000, $record->pollIntervalMs);
        self::assertSame($record, $store->findTask($record->taskId));
    }

    public function testCreateTaskAssignsDistinctIds(): void
    {
        $store = $this->buildStore();

        self::assertNotSame(
            $store->createTask('slow_compute', null, null, 1_000)->taskId,
            $store->createTask('slow_compute', null, null, 1_000)->taskId,
        );
    }

    public function testCreateTaskAssignsThirtyTwoHexCharacterIds(): void
    {
        $record = $this->buildStore()->createTask('slow_compute', null, null, 1_000);

        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $record->taskId);
    }

    public function testFindTaskReturnsNullForAnUnknownId(): void
    {
        self::assertNull($this->buildStore()->findTask('missing'));
    }

    public function testTrySetCompletedStoresTheResultAndBumpsTheTimestamp(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $this->clock->travelTo('2026-08-04T12:00:05+00:00');

        self::assertTrue($store->trySetCompleted($taskId, ['resultType' => 'complete', 'content' => []]));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Completed, $record->status);
        self::assertSame(['resultType' => 'complete', 'content' => []], $record->result);
        self::assertSame('2026-08-04T12:00:00+00:00', $record->createdAt);
        self::assertSame('2026-08-04T12:00:05+00:00', $record->lastUpdatedAt);
    }

    public function testTrySetFailedStoresTheErrorAndStatusMessage(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('failing_job', null, null, 1_000)->taskId;

        self::assertTrue($store->trySetFailed($taskId, ['code' => -32_603, 'message' => 'It broke.'], 'Upstream unavailable.'));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Failed, $record->status);
        self::assertSame(['code' => -32_603, 'message' => 'It broke.'], $record->error);
        self::assertSame('Upstream unavailable.', $record->statusMessage);
    }

    public function testTerminalTransitionsAreSticky(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        self::assertTrue($store->trySetCompleted($taskId, ['resultType' => 'complete']));
        self::assertFalse($store->trySetCancelled($taskId));
        self::assertFalse($store->trySetFailed($taskId, ['code' => -32_603]));
        self::assertFalse($store->trySetWorking($taskId));
        self::assertFalse($store->trySetInputRequired($taskId, ['k' => $this->buildElicitRequest()], null));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Completed, $record->status);
    }

    public function testACancelledTaskRefusesCompletion(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        self::assertTrue($store->trySetCancelled($taskId));
        self::assertFalse($store->trySetCompleted($taskId, ['resultType' => 'complete']));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Cancelled, $record->status);
        self::assertNull($record->result);
    }

    public function testEveryTransitionRefusesAnUnknownId(): void
    {
        $store = $this->buildStore();

        self::assertFalse($store->trySetWorking('missing'));
        self::assertFalse($store->trySetCompleted('missing', []));
        self::assertFalse($store->trySetFailed('missing', []));
        self::assertFalse($store->trySetCancelled('missing'));
        self::assertFalse($store->trySetInputRequired('missing', [], null));
        self::assertNull($store->resolveInputRequests('missing', []));
    }

    public function testTrySetInputRequiredParksRequestsAndState(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('confirm_delete', null, null, 1_000)->taskId;
        $request = $this->buildElicitRequest();

        self::assertTrue($store->trySetInputRequired($taskId, ['confirm' => $request], 'state-1'));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::InputRequired, $record->status);
        self::assertSame(['confirm' => $request], $record->pendingInputRequests);
        self::assertSame('state-1', $record->requestState);
        self::assertSame(['confirm' => true], $record->issuedInputKeys);
    }

    public function testTrySetInputRequiredRefusesAReusedKey(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('confirm_delete', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, ['confirm' => $this->buildElicitRequest()], 'state-1');
        $store->resolveInputRequests($taskId, ['confirm' => $this->buildElicitResult()]);
        $store->trySetWorking($taskId);

        $this->expectException(InputRequestKeyReusedException::class);
        $this->expectExceptionMessageIs(\sprintf('Task "%s" already issued the input-request key "confirm".', $taskId));

        $store->trySetInputRequired($taskId, ['confirm' => $this->buildElicitRequest()], 'state-2');
    }

    public function testTrySetInputRequiredAccumulatesTheKeyLedgerAcrossRounds(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('multi_input', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, ['first' => $this->buildElicitRequest()], 'state-1');
        $store->resolveInputRequests($taskId, ['first' => $this->buildElicitResult()]);
        $store->trySetWorking($taskId);

        self::assertTrue($store->trySetInputRequired($taskId, ['second' => $this->buildElicitRequest()], 'state-2'));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(['first' => true, 'second' => true], $record->issuedInputKeys);
    }

    public function testResolveInputRequestsMergesOnlyOutstandingKeys(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('multi_input', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, [
            'first' => $this->buildElicitRequest(),
            'second' => $this->buildElicitRequest(),
        ], 'state-1');

        $answer = $this->buildElicitResult();
        $record = $store->resolveInputRequests($taskId, [
            'first' => $answer,
            'unknown' => $this->buildElicitResult(),
        ]);

        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(['second'], array_keys($record->pendingInputRequests));
        self::assertSame(['first' => $answer], $record->inputResponses);
        self::assertSame(TaskStatus::InputRequired, $record->status);
        self::assertSame('state-1', $record->requestState);
    }

    public function testResolveInputRequestsAcceptsAKnownKeyAfterAnUnknownOne(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('multi_input', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, ['first' => $this->buildElicitRequest()], 'state-1');

        $answer = $this->buildElicitResult();
        $record = $store->resolveInputRequests($taskId, [
            'unknown' => $this->buildElicitResult(),
            'first' => $answer,
        ]);

        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame([], $record->pendingInputRequests);
        self::assertSame(['first' => $answer], $record->inputResponses);
    }

    public function testResolveInputRequestsWithOnlyUnknownKeysLeavesTheRecordUntouched(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('multi_input', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, ['first' => $this->buildElicitRequest()], 'state-1');
        $before = $store->findTask($taskId);

        $record = $store->resolveInputRequests($taskId, ['unknown' => $this->buildElicitResult()]);

        self::assertSame($before, $record);
    }

    public function testResolveInputRequestsOnATerminalRecordAcksWithoutChange(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $store->trySetCompleted($taskId, ['resultType' => 'complete']);
        $before = $store->findTask($taskId);

        $record = $store->resolveInputRequests($taskId, ['confirm' => $this->buildElicitResult()]);

        self::assertSame($before, $record);
    }

    public function testTrySetWorkingClearsPendingRequestsAndKeepsContinuationState(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('multi_input', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, [
            'first' => $this->buildElicitRequest(),
            'second' => $this->buildElicitRequest(),
        ], 'state-1');
        $answer = $this->buildElicitResult();
        $store->resolveInputRequests($taskId, ['first' => $answer]);

        self::assertTrue($store->trySetWorking($taskId));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Working, $record->status);
        self::assertSame([], $record->pendingInputRequests);
        self::assertSame(['first' => $answer], $record->inputResponses);
        self::assertSame('state-1', $record->requestState);
        self::assertSame(['first' => true, 'second' => true], $record->issuedInputKeys);
    }

    public function testTrySetCancelledClearsThePendingRequests(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('confirm_delete', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, ['confirm' => $this->buildElicitRequest()], 'state-1');

        self::assertTrue($store->trySetCancelled($taskId));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Cancelled, $record->status);
        self::assertSame([], $record->pendingInputRequests);
        self::assertSame([], $record->inputResponses);
        self::assertNull($record->requestState);
    }

    public function testANonTerminalTaskFailsOnceItsTtlElapses(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;

        $this->clock->travelTo('2026-08-04T12:00:00.999+00:00');
        $record = $store->findTask($taskId);

        self::assertInstanceOf(TaskRecord::class, $record);

        self::assertSame(TaskStatus::Working, $record->status);

        $this->clock->travelTo('2026-08-04T12:00:01+00:00');
        $record = $store->findTask($taskId);

        self::assertInstanceOf(TaskRecord::class, $record);

        self::assertSame(TaskStatus::Failed, $record->status);
        self::assertSame(['code' => -32_603, 'message' => 'The task did not settle within its ttl.'], $record->error);
    }

    public function testAParkedTaskFailingOnItsTtlClearsThePark(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('needs_input', null, 1_000, 1_000)->taskId;
        self::assertTrue($store->trySetInputRequired($taskId, ['city' => $this->buildElicitRequest()], 'state-token'));

        $this->clock->travelTo('2026-08-04T12:00:02+00:00');
        $record = $store->findTask($taskId);

        self::assertInstanceOf(TaskRecord::class, $record);

        self::assertSame(TaskStatus::Failed, $record->status);
        self::assertSame([], $record->pendingInputRequests);
        self::assertNull($record->requestState);
    }

    public function testACompletionArrivingAfterTheTtlElapsedIsRefused(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;

        $this->clock->travelTo('2026-08-04T12:00:02+00:00');

        self::assertFalse($store->trySetCompleted($taskId, ['resultType' => 'complete']));
    }

    public function testAnExpiryFailureIsRetainedForTtlAfterTheFailure(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;

        $this->clock->travelTo('2026-08-04T12:00:05+00:00');
        $record = $store->findTask($taskId);

        self::assertInstanceOf(TaskRecord::class, $record);

        self::assertSame(TaskStatus::Failed, $record->status);

        $this->clock->travelTo('2026-08-04T12:00:05.999+00:00');
        self::assertInstanceOf(TaskRecord::class, $store->findTask($taskId));

        $this->clock->travelTo('2026-08-04T12:00:06+00:00');
        self::assertNull($store->findTask($taskId));
    }

    public function testALiveTaskWithANullTtlNeverFails(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $this->clock->travelTo('2027-08-04T12:00:00+00:00');

        $record = $store->findTask($taskId);

        self::assertInstanceOf(TaskRecord::class, $record);

        self::assertSame(TaskStatus::Working, $record->status);
    }

    public function testCreateTaskStopsSweepingAtTheFirstUnexpiredSettledRecord(): void
    {
        $store = $this->buildStore();
        $longLived = $store->createTask('slow_compute', null, 60_000, 1_000)->taskId;
        $shortLived = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;
        $store->trySetCompleted($longLived, ['resultType' => 'complete']);
        $store->trySetCompleted($shortLived, ['resultType' => 'complete']);

        $this->clock->travel('+2 seconds');
        $created = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;

        self::assertSame([$longLived, $shortLived, $created], array_keys($this->readRecords($store)));
    }

    public function testCreateTaskLeavesAnOverdueRecordToItsNextObservation(): void
    {
        $store = $this->buildStore();
        $overdue = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;

        $this->clock->travel('+2 seconds');
        $store->createTask('slow_compute', null, 1_000, 1_000);

        $records = $this->readRecords($store);
        self::assertArrayHasKey($overdue, $records);
        self::assertSame(TaskStatus::Working, $records[$overdue]->status);
    }

    public function testAtTheCeilingCreateTaskResolvesEveryRecordBeforeEvicting(): void
    {
        $store = $this->buildStore(maxRecords: 2);
        $overdue = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;
        $live = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        $this->clock->travel('+2 seconds');
        $created = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;

        self::assertSame([$live, $created], array_keys($this->readRecords($store)));
        self::assertNull($store->findTask($overdue));
    }

    public function testAtTheCeilingCreateTaskEvictsTheOldestSettledRecord(): void
    {
        $store = $this->buildStore(maxRecords: 3);
        $first = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $second = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $live = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $store->trySetCompleted($second, ['resultType' => 'complete']);
        $store->trySetCompleted($first, ['resultType' => 'complete']);

        $created = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        self::assertSame([$first, $live, $created], array_keys($this->readRecords($store)));
        self::assertSame([$first], array_keys($this->readTerminalAt($store)));
    }

    public function testAtTheCeilingCreateTaskRefusesWhenEveryRecordIsLive(): void
    {
        $store = $this->buildStore(maxRecords: 2);
        $store->createTask('slow_compute', null, null, 1_000);
        $store->createTask('slow_compute', null, null, 1_000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The task store holds its maximum of 2 records and none of them has settled.');

        $store->createTask('slow_compute', null, null, 1_000);
    }

    public function testBelowTheCeilingCreateTaskKeepsAnUnexpiredSettledRecord(): void
    {
        $store = $this->buildStore(maxRecords: 3);
        $settled = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $store->trySetCompleted($settled, ['resultType' => 'complete']);

        $created = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        self::assertSame([$settled, $created], array_keys($this->readRecords($store)));
    }

    public function testConstructorRejectsANonPositiveCeiling(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('maxRecords must be a positive integer, 0 given.');

        // @phpstan-ignore argument.type
        new InMemoryTaskStore(maxRecords: 0);
    }

    public function testCreateTaskReadsTheClockOnceForTheWholeSweep(): void
    {
        $store = new InMemoryTaskStore($this->clock, maxRecords: 4);

        for ($i = 0; $i < 3; ++$i) {
            $taskId = $store->createTask('slow_compute', null, 300_000, 1_000)->taskId;
            $store->trySetCompleted($taskId, ['resultType' => 'complete']);
        }

        $store->createTask('slow_compute', null, 300_000, 1_000);
        $this->clock->reads = 0;

        $store->createTask('slow_compute', null, 300_000, 1_000);

        self::assertSame(1, $this->clock->reads);
        self::assertCount(4, $this->readRecords($store));
    }

    public function testAtTheCeilingCreateTaskStopsOnceTheResolveFreesRoom(): void
    {
        $store = $this->buildStore(maxRecords: 2);
        $longLived = $store->createTask('slow_compute', null, 60_000, 1_000)->taskId;
        $shortLived = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;
        $store->trySetCompleted($longLived, ['resultType' => 'complete']);
        $store->trySetCompleted($shortLived, ['resultType' => 'complete']);

        $this->clock->travel('+2 seconds');
        $created = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;

        self::assertSame([$longLived, $created], array_keys($this->readRecords($store)));
        self::assertSame([$longLived], array_keys($this->readTerminalAt($store)));
    }

    public function testATerminalTaskExpiresAtItsRetentionBoundary(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;
        $store->trySetCompleted($taskId, ['resultType' => 'complete']);

        $this->clock->travelTo('2026-08-04T12:00:00.999+00:00');
        self::assertInstanceOf(TaskRecord::class, $store->findTask($taskId));

        $this->clock->travelTo('2026-08-04T12:00:01+00:00');
        self::assertNull($store->findTask($taskId));
        self::assertNull($store->findTask($taskId));
    }

    public function testRetentionBoundaryHonoursSubSecondPrecision(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;
        $this->clock->travelTo('2026-08-04T12:00:00.500500+00:00');
        $store->trySetCompleted($taskId, ['resultType' => 'complete']);

        $this->clock->travelTo('2026-08-04T12:00:01.499000+00:00');
        self::assertInstanceOf(TaskRecord::class, $store->findTask($taskId));

        $this->clock->travelTo('2026-08-04T12:00:01.500000+00:00');
        self::assertNull($store->findTask($taskId));
    }

    public function testANullTtlNeverExpires(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $store->trySetCompleted($taskId, ['resultType' => 'complete']);
        $this->clock->travelTo('2027-08-04T12:00:00+00:00');

        self::assertInstanceOf(TaskRecord::class, $store->findTask($taskId));
    }

    /**
     * @param int<1, max> $maxRecords
     */
    private function buildStore(int $maxRecords = InMemoryTaskStore::DEFAULT_MAX_RECORDS): InMemoryTaskStore
    {
        return new InMemoryTaskStore($this->clock, $maxRecords);
    }

    /**
     * @return array<array-key, TaskRecord>
     */
    private function readRecords(InMemoryTaskStore $store): array
    {
        $records = (new \ReflectionProperty(InMemoryTaskStore::class, 'records'))->getValue($store);
        self::assertIsArray($records);
        self::assertContainsOnlyInstancesOf(TaskRecord::class, $records);

        return $records;
    }

    /**
     * @return array<array-key, \DateTimeImmutable>
     */
    private function readTerminalAt(InMemoryTaskStore $store): array
    {
        $terminalAt = (new \ReflectionProperty(InMemoryTaskStore::class, 'terminalAt'))->getValue($store);
        self::assertIsArray($terminalAt);
        self::assertContainsOnlyInstancesOf(\DateTimeImmutable::class, $terminalAt);

        return $terminalAt;
    }

    private function buildElicitRequest(): ElicitRequest
    {
        return new ElicitRequest(new ElicitRequestFormParams(
            message: 'Confirm?',
            requestedSchema: new ElicitRequestedSchema(properties: ['name' => new StringSchema()]),
        ));
    }

    private function buildElicitResult(): ElicitResult
    {
        return new ElicitResult(action: ElicitAction::Accept);
    }
}
