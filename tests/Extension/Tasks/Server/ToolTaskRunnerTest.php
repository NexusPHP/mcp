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

namespace Nexus\Mcp\Tests\Extension\Tasks\Server;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\NullCancellation;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Server\Exception\TaskLimitReachedException;
use Nexus\Mcp\Extension\Tasks\Server\Store\InMemoryTaskStore;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskRecord;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskStoreInterface;
use Nexus\Mcp\Extension\Tasks\Server\TaskCancellationRegistry;
use Nexus\Mcp\Extension\Tasks\Server\ToolTaskRunner;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(ToolTaskRunner::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class ToolTaskRunnerTest extends AbstractMcpTestCase
{
    public function testStartTaskRefusesToRunUnbound(): void
    {
        $runner = new ToolTaskRunner(new InMemoryTaskStore(), new TaskCancellationRegistry(), new ArrayLogger());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIs('The tasks broker has not been applied, so no tool handler can serve the task.');

        $runner->startTask('task-1', self::buildRequest(), self::buildContext(), null, null);
    }

    public function testACompleteResultSettlesTheTaskCompleted(): void
    {
        [$store, $runner] = self::buildRunner(new ClosureRequestHandler(
            static fn(): Result => new CallToolResult(content: [new TextContent(text: 'done')]),
        ));
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        $runner->startTask($taskId, self::buildRequest(), self::buildContext(), null, null);
        delay(0);

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Completed, $record->status);
        self::assertSame(
            ['resultType' => 'complete', 'content' => [['text' => 'done', 'type' => 'text']]],
            $record->result,
        );
    }

    public function testAToolErrorResultStillCompletes(): void
    {
        [$store, $runner] = self::buildRunner(new ClosureRequestHandler(
            static fn(): Result => new CallToolResult(content: [new TextContent(text: 'Tool execution failed.')], isError: true),
        ));
        $taskId = $store->createTask('failing_job', null, null, 1_000)->taskId;

        $runner->startTask($taskId, self::buildRequest(), self::buildContext(), null, null);
        delay(0);

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Completed, $record->status);
        self::assertIsArray($record->result);
        self::assertTrue($record->result['isError'] ?? null);
    }

    public function testAnInputRequiredResultParksTheTask(): void
    {
        $request = self::buildElicitRequest();
        [$store, $runner] = self::buildRunner(new ClosureRequestHandler(
            static fn(): Result => new InputRequiredResult(inputRequests: ['confirm' => $request], requestState: 'state-1'),
        ));
        $taskId = $store->createTask('confirm_delete', null, null, 1_000)->taskId;

        $runner->startTask($taskId, self::buildRequest(), self::buildContext(), null, null);
        delay(0);

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::InputRequired, $record->status);
        self::assertSame(['confirm' => $request], $record->pendingInputRequests);
        self::assertSame('state-1', $record->requestState);
    }

    public function testARequestStateOnlyParkFailsTheTask(): void
    {
        [$store, $runner] = self::buildRunner(new ClosureRequestHandler(
            static fn(): Result => new InputRequiredResult(requestState: 'token-1'),
        ));
        $taskId = $store->createTask('confirm_delete', null, null, 1_000)->taskId;

        $runner->startTask($taskId, self::buildRequest(), self::buildContext(), null, null);
        delay(0);

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Failed, $record->status);
        self::assertSame(
            ['code' => -32_603, 'message' => 'The tool parked the task without any input requests.'],
            $record->error,
        );
    }

    public function testAReusedInputRequestKeyFailsTheTaskWithItsMessage(): void
    {
        $request = self::buildElicitRequest();
        [$store, $runner] = self::buildRunner(new ClosureRequestHandler(
            static fn(): Result => new InputRequiredResult(inputRequests: ['confirm' => $request]),
        ));
        $taskId = $store->createTask('confirm_delete', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, ['confirm' => $request], null);
        $store->trySetWorking($taskId);

        $runner->startTask($taskId, self::buildRequest(), self::buildContext(), null, null);
        delay(0);

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Failed, $record->status);
        self::assertSame(
            ['code' => -32_603, 'message' => \sprintf('Task "%s" already issued the input-request key "confirm".', $taskId)],
            $record->error,
        );
    }

    public function testAProtocolExceptionFailsTheTaskWithItsErrorShape(): void
    {
        [$store, $runner] = self::buildRunner(new ClosureRequestHandler(
            static fn(): Result => throw new InvalidParamsException(new RequestId(id: 7), 'Bad params.', errorData: ['field' => 'name']),
        ));
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        $runner->startTask($taskId, self::buildRequest(), self::buildContext(), null, null);
        delay(0);

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Failed, $record->status);
        self::assertSame(
            ['code' => -32_602, 'message' => 'Bad params.', 'data' => ['field' => 'name']],
            $record->error,
        );
    }

    public function testAGenericThrowableFailsTheTaskWithoutLeakingItsMessage(): void
    {
        $logger = new ArrayLogger();
        [$store, $runner] = self::buildRunner(new ClosureRequestHandler(
            static fn(): Result => throw new \RuntimeException('secret path /etc/passwd'),
        ), $logger);
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        $runner->startTask($taskId, self::buildRequest(), self::buildContext(), null, null);
        delay(0);

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Failed, $record->status);
        self::assertSame(['code' => -32_603, 'message' => 'Task execution failed.'], $record->error);
        $records = $logger->recordsMatching(
            LogLevel::ERROR,
            'Uncaught task executor exception. Failing task {taskId} with a generic error.',
        );
        self::assertCount(1, $records);
        self::assertSame($taskId, $records[0]['context']['taskId'] ?? null);
        self::assertInstanceOf(\RuntimeException::class, $records[0]['context']['exception'] ?? null);
    }

    public function testCancellationSettlesTheTaskCancelled(): void
    {
        [$store, $runner] = self::buildRunner(new ClosureRequestHandler(
            static fn(): Result => throw new CancelledException(),
        ));
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        $runner->startTask($taskId, self::buildRequest(), self::buildContext(), null, null);
        delay(0);

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Cancelled, $record->status);
    }

    public function testASettledRunReleasesItsCancellationSource(): void
    {
        $registry = new TaskCancellationRegistry();
        $store = new InMemoryTaskStore();
        $runner = new ToolTaskRunner($store, $registry, new ArrayLogger());
        $runner->bindInnerHandler(new ClosureRequestHandler(
            static fn(): Result => new CallToolResult(content: []),
        ));
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        $runner->startTask($taskId, self::buildRequest(), self::buildContext(), null, null);
        delay(0);

        $sources = (new \ReflectionProperty(TaskCancellationRegistry::class, 'sources'))->getValue($registry);
        self::assertSame([], $sources);
    }

    public function testConstructorRefusesANonPositiveCap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('maxRunningTasks must be a positive integer, 0 given.');

        // @phpstan-ignore argument.type
        new ToolTaskRunner(new InMemoryTaskStore(), new TaskCancellationRegistry(), new ArrayLogger(), 0);
    }

    public function testEnsureCapacityRefusesOnceTheCapIsRunning(): void
    {
        $gate = new DeferredFuture();
        [$store, $runner] = self::buildRunner(new ClosureRequestHandler(
            static function () use ($gate): Result {
                $gate->getFuture()->await();

                return new CallToolResult(content: []);
            },
        ), maxRunningTasks: 1);
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        $runner->ensureCapacity(new RequestId(id: 7));
        $runner->startTask($taskId, self::buildRequest(), self::buildContext(), null, null);

        try {
            $runner->ensureCapacity(new RequestId(id: 8));
            self::fail('A second task must be refused while the first is running.');
        } catch (TaskLimitReachedException $e) {
            self::assertSame('Task limit reached: this server runs at most 1 tasks at once.', $e->getMessage());
            self::assertSame(8, $e->requestId?->id);
        }

        $gate->complete();
        delay(0);

        $runner->ensureCapacity(new RequestId(id: 9));
        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Completed, $record->status);
    }

    public function testEnsureCapacityAdmitsUpToTheCap(): void
    {
        $gate = new DeferredFuture();
        [$store, $runner] = self::buildRunner(new ClosureRequestHandler(
            static function () use ($gate): Result {
                $gate->getFuture()->await();

                return new CallToolResult(content: []);
            },
        ), maxRunningTasks: 2);

        $runner->startTask($store->createTask('slow_compute', null, null, 1_000)->taskId, self::buildRequest(), self::buildContext(), null, null);
        $runner->ensureCapacity(new RequestId(id: 8));
        $runner->startTask($store->createTask('slow_compute', null, null, 1_000)->taskId, self::buildRequest(), self::buildContext(), null, null);

        $this->expectException(TaskLimitReachedException::class);
        $this->expectExceptionMessageIs('Task limit reached: this server runs at most 2 tasks at once.');

        try {
            $runner->ensureCapacity(new RequestId(id: 9));
        } finally {
            $gate->complete();
            delay(0);
        }
    }

    public function testAStoreThatThrowsOnSettleStillReleasesTheSlot(): void
    {
        $registry = new TaskCancellationRegistry();
        $store = new class (new InMemoryTaskStore()) implements TaskStoreInterface {
            public function __construct(private readonly InMemoryTaskStore $inner)
            {
            }

            #[\Override]
            public function createTask(string $toolName, ?array $arguments, ?int $ttlMs, int $pollIntervalMs): TaskRecord
            {
                return $this->inner->createTask($toolName, $arguments, $ttlMs, $pollIntervalMs);
            }

            #[\Override]
            public function findTask(string $taskId): ?TaskRecord
            {
                return $this->inner->findTask($taskId);
            }

            #[\Override]
            public function trySetWorking(string $taskId): bool
            {
                return $this->inner->trySetWorking($taskId);
            }

            #[\Override]
            public function trySetCompleted(string $taskId, array $result): bool
            {
                throw new \RuntimeException('The store is unavailable.');
            }

            #[\Override]
            public function trySetFailed(string $taskId, array $error, ?string $statusMessage = null): bool
            {
                throw new \RuntimeException('The store is unavailable.');
            }

            #[\Override]
            public function trySetCancelled(string $taskId): bool
            {
                return $this->inner->trySetCancelled($taskId);
            }

            #[\Override]
            public function trySetInputRequired(string $taskId, array $inputRequests, ?string $requestState): bool
            {
                return $this->inner->trySetInputRequired($taskId, $inputRequests, $requestState);
            }

            #[\Override]
            public function resolveInputRequests(string $taskId, array $inputResponses): ?TaskRecord
            {
                return $this->inner->resolveInputRequests($taskId, $inputResponses);
            }
        };
        $runner = new ToolTaskRunner($store, $registry, new ArrayLogger(), maxRunningTasks: 1);
        $runner->bindInnerHandler(new ClosureRequestHandler(static fn(): Result => new CallToolResult(content: [])));
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;

        $runner->startTask($taskId, self::buildRequest(), self::buildContext(), null, null);
        delay(0);

        self::assertCount(0, $registry);
        $runner->ensureCapacity(new RequestId(id: 8));
    }

    /**
     * @param int<1, max> $maxRunningTasks
     *
     * @return array{InMemoryTaskStore, ToolTaskRunner}
     */
    private static function buildRunner(ClosureRequestHandler $inner, ?ArrayLogger $logger = null, int $maxRunningTasks = 1_024): array
    {
        $store = new InMemoryTaskStore();
        $runner = new ToolTaskRunner($store, new TaskCancellationRegistry(), $logger ?? new ArrayLogger(), $maxRunningTasks);
        $runner->bindInnerHandler($inner);

        return [$store, $runner];
    }

    private static function buildRequest(): CallToolRequest
    {
        return CallToolRequest::fromArray([
            'id' => 7,
            'params' => ['_meta' => RequestMetaObjectFactory::shape(), 'name' => 'slow_compute'],
        ]);
    }

    private static function buildContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }

    private static function buildElicitRequest(): ElicitRequest
    {
        return new ElicitRequest(new ElicitRequestFormParams(
            message: 'Confirm?',
            requestedSchema: new ElicitRequestedSchema(properties: ['name' => new StringSchema()]),
        ));
    }
}
