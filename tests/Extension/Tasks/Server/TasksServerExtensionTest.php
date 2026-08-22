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

use Amp\DeferredFuture;
use Amp\NullCancellation;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Core\Schema\Tool\Tool;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\Request\CancelTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\GetTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\UpdateTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Extension\Tasks\Server\Exception\TaskLimitReachedException;
use Nexus\Mcp\Extension\Tasks\Server\Handler\CancelTaskRequestHandler;
use Nexus\Mcp\Extension\Tasks\Server\Handler\GetTaskRequestHandler;
use Nexus\Mcp\Extension\Tasks\Server\Handler\TaskBrokeringCallToolHandler;
use Nexus\Mcp\Extension\Tasks\Server\Handler\UpdateTaskRequestHandler;
use Nexus\Mcp\Extension\Tasks\Server\Store\InMemoryTaskStore;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskRecord;
use Nexus\Mcp\Extension\Tasks\Server\TasksServerExtension;
use Nexus\Mcp\Extension\Tasks\Server\TaskSupport;
use Nexus\Mcp\Extension\Tasks\Server\ToolTaskPolicy;
use Nexus\Mcp\Extension\Tasks\Tasks;
use Nexus\Mcp\Server\ServerBuilder;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\ClosureRequestHandler;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(TasksServerExtension::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class TasksServerExtensionTest extends AbstractMcpTestCase
{
    public function testDeclaresTheOfficialIdentifierWithEmptySettings(): void
    {
        $extension = new TasksServerExtension();

        self::assertSame('io.modelcontextprotocol/tasks', $extension->getIdentifier());
        self::assertSame([], $extension->getSettings());
        self::assertSame([], $extension->getNotifications());
        self::assertSame([], $extension->getNotificationHandlers());
    }

    public function testDeclaresTheThreeTasksMethods(): void
    {
        $extension = new TasksServerExtension();

        self::assertSame([
            'tasks/get' => GetTaskRequest::class,
            'tasks/update' => UpdateTaskRequest::class,
            'tasks/cancel' => CancelTaskRequest::class,
        ], $extension->getRequests());

        $handlers = $extension->getRequestHandlers();
        self::assertSame(['tasks/get', 'tasks/update', 'tasks/cancel'], array_keys($handlers));
        self::assertInstanceOf(GetTaskRequestHandler::class, $handlers['tasks/get'] ?? null);
        self::assertInstanceOf(UpdateTaskRequestHandler::class, $handlers['tasks/update'] ?? null);
        self::assertInstanceOf(CancelTaskRequestHandler::class, $handlers['tasks/cancel'] ?? null);
    }

    public function testDecoratesToolsCallWithTheBroker(): void
    {
        $extension = new TasksServerExtension(
            store: new InMemoryTaskStore(),
            toolPolicies: ['slow_compute' => new ToolTaskPolicy(support: TaskSupport::Optional)],
        );

        $decorators = $extension->getRequestHandlerDecorators();
        self::assertSame(['tools/call'], array_keys($decorators));
        $decorate = $decorators['tools/call'] ?? null;

        if (null === $decorate) {
            self::fail('The tasks extension must decorate "tools/call".');
        }

        $broker = $decorate(new ClosureRequestHandler(
            static fn(): Result => new CallToolResult(content: [new TextContent(text: 'sync')]),
        ));

        self::assertInstanceOf(TaskBrokeringCallToolHandler::class, $broker);
    }

    public function testTheDecoratedBrokerCreatesTasksWithTheExtensionDefaults(): void
    {
        $store = new InMemoryTaskStore();
        $extension = new TasksServerExtension(
            store: $store,
            toolPolicies: ['slow_compute' => new ToolTaskPolicy(support: TaskSupport::Optional)],
        );

        $decorate = $extension->getRequestHandlerDecorators()['tools/call'] ?? null;

        if (null === $decorate) {
            self::fail('The tasks extension must decorate "tools/call".');
        }

        $broker = $decorate(new ClosureRequestHandler(
            static fn(): Result => new CallToolResult(content: [new TextContent(text: 'done')]),
        ));

        $result = $broker->handle(
            CallToolRequest::fromArray([
                'id' => 7,
                'params' => ['_meta' => RequestMetaObjectFactory::shape(
                    clientCapabilities: new ClientCapabilities(extensions: [Tasks::IDENTIFIER => []]),
                ), 'name' => 'slow_compute'],
            ]),
            new ServerContext(
                new RequestId(id: 7),
                new NullCancellation(),
                RequestMetaObjectFactory::create(
                    clientCapabilities: new ClientCapabilities(extensions: [Tasks::IDENTIFIER => []]),
                ),
                new RecordingSender(),
            ),
        );

        self::assertInstanceOf(CreateTaskResult::class, $result);
        self::assertSame(300_000, $result->ttlMs);
        self::assertSame(1_000, $result->pollIntervalMs);

        delay(0);

        $settled = $store->findTask($result->taskId);
        self::assertInstanceOf(TaskRecord::class, $settled);
        self::assertSame(TaskStatus::Completed, $settled->status);
    }

    public function testTheRunningTaskCapReachesTheBroker(): void
    {
        $gate = new DeferredFuture();
        $extension = new TasksServerExtension(
            toolPolicies: ['slow_compute' => new ToolTaskPolicy(support: TaskSupport::Optional)],
            maxRunningTasks: 1,
        );
        $decorate = $extension->getRequestHandlerDecorators()['tools/call'] ?? null;

        if (null === $decorate) {
            self::fail('The tasks extension must decorate "tools/call".');
        }

        $broker = $decorate(new ClosureRequestHandler(static function () use ($gate): Result {
            $gate->getFuture()->await();

            return new CallToolResult(content: []);
        }));
        $request = CallToolRequest::fromArray([
            'id' => 7,
            'params' => ['_meta' => RequestMetaObjectFactory::shape(
                clientCapabilities: new ClientCapabilities(extensions: [Tasks::IDENTIFIER => []]),
            ), 'name' => 'slow_compute'],
        ]);
        $context = new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(
                clientCapabilities: new ClientCapabilities(extensions: [Tasks::IDENTIFIER => []]),
            ),
            new RecordingSender(),
        );

        self::assertInstanceOf(CreateTaskResult::class, $broker->handle($request, $context));

        try {
            $broker->handle($request, $context);
            self::fail('The second task must be refused at a cap of one.');
        } catch (TaskLimitReachedException $e) {
            self::assertSame(['limit' => 1], $e->errorData);
        } finally {
            $gate->complete();
            delay(0);
        }
    }

    public function testRegistersOnAServerBuilderWithATaskCapableTool(): void
    {
        $builder = (new ServerBuilder())
            ->setServerInfo('demo', '1.0.0')
            ->addTool(
                new Tool(name: 'slow_compute', inputSchema: ['type' => 'object']),
                static fn(?array $args, $ctx): CallToolResult => new CallToolResult(content: [new TextContent(text: 'done')]),
            )
            ->enableExtension(new TasksServerExtension(
                toolPolicies: ['slow_compute' => new ToolTaskPolicy(support: TaskSupport::Optional)],
            ))
        ;

        $this->expectNotToPerformAssertions();
        $builder->build();
    }
}
