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
    private \DateTimeImmutable $now;

    #[\Override]
    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-08-04T12:00:00+00:00');
    }

    public function testCreateTaskSweepsExpiredTerminalRecords(): void
    {
        $store = $this->buildStore();
        $expired = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;
        $store->trySetCompleted($expired, ['resultType' => 'complete']);

        $this->now = $this->now->modify('+2 seconds');
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
        $this->now = new \DateTimeImmutable('2026-08-04T12:00:05+00:00');

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

        self::assertTrue($store->trySetFailed($taskId, ['code' => -32603, 'message' => 'It broke.'], 'Upstream unavailable.'));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Failed, $record->status);
        self::assertSame(['code' => -32603, 'message' => 'It broke.'], $record->error);
        self::assertSame('Upstream unavailable.', $record->statusMessage);
    }

    public function testTerminalTransitionsAreSticky(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        self::assertTrue($store->trySetCompleted($taskId, ['resultType' => 'complete']));
        self::assertFalse($store->trySetCancelled($taskId));
        self::assertFalse($store->trySetFailed($taskId, ['code' => -32603]));
        self::assertFalse($store->trySetWorking($taskId));
        self::assertFalse($store->trySetInputRequired($taskId, ['k' => self::buildElicitRequest()], null));

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
        $request = self::buildElicitRequest();

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
        $store->trySetInputRequired($taskId, ['confirm' => self::buildElicitRequest()], 'state-1');
        $store->resolveInputRequests($taskId, ['confirm' => self::buildElicitResult()]);
        $store->trySetWorking($taskId);

        $this->expectException(InputRequestKeyReusedException::class);
        $this->expectExceptionMessageIs(\sprintf('Task "%s" already issued the input-request key "confirm".', $taskId));

        $store->trySetInputRequired($taskId, ['confirm' => self::buildElicitRequest()], 'state-2');
    }

    public function testTrySetInputRequiredAccumulatesTheKeyLedgerAcrossRounds(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('multi_input', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, ['first' => self::buildElicitRequest()], 'state-1');
        $store->resolveInputRequests($taskId, ['first' => self::buildElicitResult()]);
        $store->trySetWorking($taskId);

        self::assertTrue($store->trySetInputRequired($taskId, ['second' => self::buildElicitRequest()], 'state-2'));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(['first' => true, 'second' => true], $record->issuedInputKeys);
    }

    public function testResolveInputRequestsMergesOnlyOutstandingKeys(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('multi_input', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, [
            'first' => self::buildElicitRequest(),
            'second' => self::buildElicitRequest(),
        ], 'state-1');

        $answer = self::buildElicitResult();
        $record = $store->resolveInputRequests($taskId, [
            'first' => $answer,
            'unknown' => self::buildElicitResult(),
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
        $store->trySetInputRequired($taskId, ['first' => self::buildElicitRequest()], 'state-1');

        $answer = self::buildElicitResult();
        $record = $store->resolveInputRequests($taskId, [
            'unknown' => self::buildElicitResult(),
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
        $store->trySetInputRequired($taskId, ['first' => self::buildElicitRequest()], 'state-1');
        $before = $store->findTask($taskId);

        $record = $store->resolveInputRequests($taskId, ['unknown' => self::buildElicitResult()]);

        self::assertSame($before, $record);
    }

    public function testResolveInputRequestsOnATerminalRecordAcksWithoutChange(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $store->trySetCompleted($taskId, ['resultType' => 'complete']);
        $before = $store->findTask($taskId);

        $record = $store->resolveInputRequests($taskId, ['confirm' => self::buildElicitResult()]);

        self::assertSame($before, $record);
    }

    public function testTrySetWorkingClearsPendingRequestsAndKeepsContinuationState(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('multi_input', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, [
            'first' => self::buildElicitRequest(),
            'second' => self::buildElicitRequest(),
        ], 'state-1');
        $answer = self::buildElicitResult();
        $store->resolveInputRequests($taskId, ['first' => $answer]);

        self::assertTrue($store->trySetWorking($taskId));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Working, $record->status);
        // The unanswered 'second' is abandoned: a re-dispatch starts fresh.
        self::assertSame([], $record->pendingInputRequests);
        self::assertSame(['first' => $answer], $record->inputResponses);
        self::assertSame('state-1', $record->requestState);
        self::assertSame(['first' => true, 'second' => true], $record->issuedInputKeys);
    }

    public function testTrySetCancelledClearsThePendingRequests(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('confirm_delete', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, ['confirm' => self::buildElicitRequest()], 'state-1');

        self::assertTrue($store->trySetCancelled($taskId));

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Cancelled, $record->status);
        // Leftover pending requests would make the cancelled record unprojectable.
        self::assertSame([], $record->pendingInputRequests);
        self::assertSame([], $record->inputResponses);
        self::assertNull($record->requestState);
    }

    public function testALiveTaskNeverExpires(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;
        $this->now = new \DateTimeImmutable('2026-08-05T12:00:00+00:00');

        self::assertInstanceOf(TaskRecord::class, $store->findTask($taskId));
    }

    public function testATerminalTaskExpiresAtItsRetentionBoundary(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;
        $store->trySetCompleted($taskId, ['resultType' => 'complete']);

        $this->now = new \DateTimeImmutable('2026-08-04T12:00:00.999+00:00');
        self::assertInstanceOf(TaskRecord::class, $store->findTask($taskId));

        $this->now = new \DateTimeImmutable('2026-08-04T12:00:01+00:00');
        self::assertNull($store->findTask($taskId));
        self::assertNull($store->findTask($taskId));
    }

    public function testRetentionBoundaryHonoursSubSecondPrecision(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, 1_000, 1_000)->taskId;
        $this->now = new \DateTimeImmutable('2026-08-04T12:00:00.500500+00:00');
        $store->trySetCompleted($taskId, ['resultType' => 'complete']);

        $this->now = new \DateTimeImmutable('2026-08-04T12:00:01.499000+00:00');
        self::assertInstanceOf(TaskRecord::class, $store->findTask($taskId));

        $this->now = new \DateTimeImmutable('2026-08-04T12:00:01.500000+00:00');
        self::assertNull($store->findTask($taskId));
    }

    public function testANullTtlNeverExpires(): void
    {
        $store = $this->buildStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $store->trySetCompleted($taskId, ['resultType' => 'complete']);
        $this->now = new \DateTimeImmutable('2027-08-04T12:00:00+00:00');

        self::assertInstanceOf(TaskRecord::class, $store->findTask($taskId));
    }

    private function buildStore(): InMemoryTaskStore
    {
        return new InMemoryTaskStore(fn(): \DateTimeImmutable => $this->now);
    }

    private static function buildElicitRequest(): ElicitRequest
    {
        return new ElicitRequest(new ElicitRequestFormParams(
            message: 'Confirm?',
            requestedSchema: new ElicitRequestedSchema(properties: ['name' => new StringSchema()]),
        ));
    }

    private static function buildElicitResult(): ElicitResult
    {
        return new ElicitResult(action: ElicitAction::Accept);
    }
}
