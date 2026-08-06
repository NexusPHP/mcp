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
use Nexus\Mcp\Extension\Tasks\Server\Store\InMemoryTaskStore;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskRecord;
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
            ['code' => -32603, 'message' => 'The tool parked the task without any input requests.'],
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
            ['code' => -32603, 'message' => \sprintf('Task "%s" already issued the input-request key "confirm".', $taskId)],
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
            ['code' => -32602, 'message' => 'Bad params.', 'data' => ['field' => 'name']],
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
        self::assertSame(['code' => -32603, 'message' => 'Task execution failed.'], $record->error);
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

    /**
     * @return array{InMemoryTaskStore, ToolTaskRunner}
     */
    private static function buildRunner(ClosureRequestHandler $inner, ?ArrayLogger $logger = null): array
    {
        $store = new InMemoryTaskStore();
        $runner = new ToolTaskRunner($store, new TaskCancellationRegistry(), $logger ?? new ArrayLogger());
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
