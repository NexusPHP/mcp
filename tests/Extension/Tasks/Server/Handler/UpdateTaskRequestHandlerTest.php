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

use Amp\NullCancellation;
use Nexus\Mcp\Core\Exception\InvalidParamsException;
use Nexus\Mcp\Core\Schema\ContentBlock\TextContent;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\CallToolResult;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\Request\UpdateTaskRequest;
use Nexus\Mcp\Extension\Tasks\Server\Handler\UpdateTaskRequestHandler;
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

use function Amp\delay;

/**
 * @internal
 */
#[CoversClass(UpdateTaskRequestHandler::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class UpdateTaskRequestHandlerTest extends AbstractMcpTestCase
{
    public function testAnUnknownTaskIdIsInvalidParams(): void
    {
        $handler = new UpdateTaskRequestHandler(new InMemoryTaskStore(), self::buildUnboundRunner(new InMemoryTaskStore()));

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"params.taskId" does not name a known task.');

        $handler->handle(self::buildRequest('missing'), self::buildContext());
    }

    public function testATerminalTaskAcksWithoutARedispatch(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $store->trySetCompleted($taskId, ['resultType' => 'complete']);

        $handler = new UpdateTaskRequestHandler($store, self::buildUnboundRunner($store));

        $handler->handle(self::buildRequest($taskId), self::buildContext());

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Completed, $record->status);
    }

    public function testAPartialFulfilmentStaysParkedWithoutARedispatch(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('multi_input', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, [
            'first' => self::buildElicitRequest(),
            'second' => self::buildElicitRequest(),
        ], 'state-1');

        $handler = new UpdateTaskRequestHandler($store, self::buildUnboundRunner($store));

        $handler->handle(self::buildRequest($taskId, ['first' => ['action' => 'accept']]), self::buildContext());

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::InputRequired, $record->status);
        self::assertSame(['second'], array_keys($record->pendingInputRequests));
    }

    public function testAMalformedAnswerForAnUnknownKeyIsIgnored(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('confirm_delete', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, ['confirm' => self::buildElicitRequest()], 'state-1');

        $handler = new UpdateTaskRequestHandler($store, self::buildUnboundRunner($store));

        $handler->handle(self::buildRequest($taskId, ['unknown-key' => ['ignored' => true]]), self::buildContext());

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::InputRequired, $record->status);
        self::assertSame(['confirm'], array_keys($record->pendingInputRequests));
    }

    public function testAMalformedAnswerForAPendingKeyIsInvalidParams(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('confirm_delete', null, null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, ['confirm' => self::buildElicitRequest()], 'state-1');

        $handler = new UpdateTaskRequestHandler($store, self::buildUnboundRunner($store));

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"params.inputResponses" entry "confirm" is not a valid input response.');

        $handler->handle(self::buildRequest($taskId, ['confirm' => ['garbled' => true]]), self::buildContext());
    }

    public function testAFullFulfilmentFlipsToWorkingAndRedispatches(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('confirm_delete', ['target' => 'archive'], null, 1_000)->taskId;
        $store->trySetInputRequired($taskId, ['confirm' => self::buildElicitRequest()], 'state-1');

        $seen = null;
        $runner = new ToolTaskRunner($store, new TaskCancellationRegistry(), new ArrayLogger());
        $runner->bindInnerHandler(new ClosureRequestHandler(
            static function (JsonRpcRequest $request, $context) use (&$seen): Result {
                \assert($request instanceof CallToolRequest);
                \assert($context instanceof ServerContext);
                $seen = [
                    'name' => $request->params->name,
                    'arguments' => $request->params->arguments,
                    'responses' => array_keys($context->inputResponses ?? []),
                    'requestState' => $context->requestState,
                ];

                return new CallToolResult(content: [new TextContent(text: 'resumed')]);
            },
        ));

        $handler = new UpdateTaskRequestHandler($store, $runner);
        $handler->handle(self::buildRequest($taskId, ['confirm' => ['action' => 'accept']]), self::buildContext());

        $flipped = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $flipped);
        self::assertSame(TaskStatus::Working, $flipped->status);

        delay(0);

        $settled = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $settled);
        self::assertSame(TaskStatus::Completed, $settled->status);
        self::assertSame(
            [
                'name' => 'confirm_delete',
                'arguments' => ['target' => 'archive'],
                'responses' => ['confirm'],
                'requestState' => 'state-1',
            ],
            $seen,
        );
    }

    private static function buildUnboundRunner(InMemoryTaskStore $store): ToolTaskRunner
    {
        return new ToolTaskRunner($store, new TaskCancellationRegistry(), new ArrayLogger());
    }

    /**
     * @param non-empty-string                    $taskId
     * @param array<string, array<string, mixed>> $responses
     */
    private static function buildRequest(string $taskId, array $responses = []): UpdateTaskRequest
    {
        return UpdateTaskRequest::fromArray([
            'id' => 7,
            'params' => [
                '_meta' => RequestMetaObjectFactory::shape(),
                'taskId' => $taskId,
                'inputResponses' => $responses,
            ],
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
