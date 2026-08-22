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

namespace Nexus\Mcp\Tests\Extension\Tasks\Server\Handler;

use Amp\DeferredFuture;
use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Extension\Tasks\Server\Exception\TaskLimitReachedException;
use Nexus\Mcp\Extension\Tasks\Server\Handler\TaskBrokeringCallToolHandler;
use Nexus\Mcp\Extension\Tasks\Server\Store\InMemoryTaskStore;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskRecord;
use Nexus\Mcp\Extension\Tasks\Server\TaskCancellationRegistry;
use Nexus\Mcp\Extension\Tasks\Server\TaskSupport;
use Nexus\Mcp\Extension\Tasks\Server\ToolTaskPolicy;
use Nexus\Mcp\Extension\Tasks\Server\ToolTaskRunner;
use Nexus\Mcp\Server\Exception\MissingRequiredClientCapabilityException;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\ArrayLogger;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(TaskBrokeringCallToolHandler::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class TaskBrokeringCallToolHandlerTest extends AbstractMcpTestCase
{
    private const string IDENTIFIER = 'io.modelcontextprotocol/tasks';

    public function testAToolWithoutAPolicyPassesThrough(): void
    {
        $marker = new CallToolResult(content: [new TextContent(text: 'sync')]);
        [$broker] = $this->buildBroker([], $marker);

        $result = $broker->handle($this->buildRequest('greet'), $this->buildContext(declared: true));

        self::assertSame($marker, $result);
    }

    public function testAnOptionalToolRunsSynchronouslyForANonDeclaringClient(): void
    {
        $marker = new CallToolResult(content: [new TextContent(text: 'sync')]);
        [$broker] = $this->buildBroker(['slow_compute' => new ToolTaskPolicy(support: TaskSupport::Optional)], $marker);

        $result = $broker->handle($this->buildRequest('slow_compute'), $this->buildContext(declared: false));

        self::assertSame($marker, $result);
    }

    public function testARequiredToolRefusesANonDeclaringClient(): void
    {
        [$broker] = $this->buildBroker(['failing_job' => new ToolTaskPolicy(support: TaskSupport::Required)], new CallToolResult(content: []));

        $this->expectException(MissingRequiredClientCapabilityException::class);
        $this->expectExceptionMessageIs('This request requires client capabilities the client did not declare: extensions.io.modelcontextprotocol/tasks.');

        $broker->handle($this->buildRequest('failing_job'), $this->buildContext(declared: false));
    }

    public function testADeclaringClientReceivesATaskHandle(): void
    {
        [$broker, $store] = $this->buildBroker(
            ['slow_compute' => new ToolTaskPolicy(support: TaskSupport::Optional)],
            new CallToolResult(content: [new TextContent(text: 'done')]),
        );

        $result = $broker->handle($this->buildRequest('slow_compute'), $this->buildContext(declared: true));

        self::assertInstanceOf(CreateTaskResult::class, $result);
        self::assertSame(TaskStatus::Working, $result->status);
        self::assertSame(300_000, $result->ttlMs);
        self::assertSame(1_000, $result->pollIntervalMs);

        $record = $store->findTask($result->taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Working, $record->status);

        delay(0);

        $settled = $store->findTask($result->taskId);
        self::assertInstanceOf(TaskRecord::class, $settled);
        self::assertSame(TaskStatus::Completed, $settled->status);
    }

    public function testAResolvesInputFirstToolPassesThroughWithoutAContinuationToken(): void
    {
        $marker = new CallToolResult(content: [new TextContent(text: 'round-1')]);
        [$broker] = $this->buildBroker(
            ['confirm_delete' => new ToolTaskPolicy(support: TaskSupport::Optional, resolvesInputFirst: true)],
            $marker,
        );

        $result = $broker->handle($this->buildRequest('confirm_delete'), $this->buildContext(declared: true));

        self::assertSame($marker, $result);
    }

    public function testAResolvesInputFirstToolCreatesATaskOnceTheTokenArrives(): void
    {
        [$broker, $store] = $this->buildBroker(
            ['confirm_delete' => new ToolTaskPolicy(support: TaskSupport::Optional, resolvesInputFirst: true)],
            new CallToolResult(content: [new TextContent(text: 'final')]),
        );

        $result = $broker->handle(
            $this->buildRequest('confirm_delete'),
            $this->buildContext(declared: true, requestState: 'state-1'),
        );

        self::assertInstanceOf(CreateTaskResult::class, $result);
        delay(0);

        $settled = $store->findTask($result->taskId);
        self::assertInstanceOf(TaskRecord::class, $settled);
        self::assertSame(TaskStatus::Completed, $settled->status);
    }

    public function testACallPastTheRunningTaskCapIsRefusedBeforeAnyRecordExists(): void
    {
        $gate = new DeferredFuture();
        $store = new InMemoryTaskStore();
        $runner = new ToolTaskRunner($store, new TaskCancellationRegistry(), new ArrayLogger(), maxRunningTasks: 1);
        $inner = new ClosureRequestHandler(static function () use ($gate): Result {
            $gate->getFuture()->await();

            return new CallToolResult(content: []);
        });
        $runner->bindInnerHandler($inner);
        $broker = new TaskBrokeringCallToolHandler(
            inner: $inner,
            identifier: self::IDENTIFIER,
            store: $store,
            runner: $runner,
            toolPolicies: ['slow_compute' => new ToolTaskPolicy(support: TaskSupport::Optional)],
            defaultTtlMs: 300_000,
            defaultPollIntervalMs: 1_000,
        );
        $first = $broker->handle($this->buildRequest('slow_compute'), $this->buildContext(declared: true));
        self::assertInstanceOf(CreateTaskResult::class, $first);

        try {
            $broker->handle($this->buildRequest('slow_compute'), $this->buildContext(declared: true));
            self::fail('The second call must be refused while the first task runs.');
        } catch (TaskLimitReachedException $e) {
            self::assertSame(['limit' => 1], $e->errorData);
            self::assertSame(7, $e->requestId?->id);
        }

        $records = (new \ReflectionProperty(InMemoryTaskStore::class, 'records'))->getValue($store);
        self::assertIsArray($records);
        self::assertCount(1, $records, 'A refused call leaves no record behind.');

        $gate->complete();
        delay(0);
    }

    /**
     * @param array<non-empty-string, ToolTaskPolicy> $policies
     *
     * @return array{TaskBrokeringCallToolHandler, InMemoryTaskStore}
     */
    private function buildBroker(array $policies, Result $innerResult): array
    {
        $store = new InMemoryTaskStore();
        $runner = new ToolTaskRunner($store, new TaskCancellationRegistry(), new ArrayLogger());
        $inner = new ClosureRequestHandler(static fn(): Result => $innerResult);
        $runner->bindInnerHandler($inner);

        $broker = new TaskBrokeringCallToolHandler(
            inner: $inner,
            identifier: self::IDENTIFIER,
            store: $store,
            runner: $runner,
            toolPolicies: $policies,
            defaultTtlMs: 300_000,
            defaultPollIntervalMs: 1_000,
        );

        return [$broker, $store];
    }

    /**
     * @param non-empty-string $name
     */
    private function buildRequest(string $name): CallToolRequest
    {
        return CallToolRequest::fromArray([
            'id' => 7,
            'params' => ['_meta' => RequestMetaObjectFactory::shape(), 'name' => $name],
        ]);
    }

    private function buildContext(bool $declared, ?string $requestState = null): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(
                clientCapabilities: $declared ? new ClientCapabilities(extensions: [self::IDENTIFIER => []]) : new ClientCapabilities(),
            ),
            new RecordingSender(),
            requestState: $requestState,
        );
    }
}
