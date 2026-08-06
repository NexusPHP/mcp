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
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequest;
use Nexus\Mcp\Core\Schema\Elicitation\ElicitRequestedSchema;
use Nexus\Mcp\Core\Schema\Elicitation\StringSchema;
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Core\Schema\RequestParams\ElicitRequestFormParams;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\Request\GetTaskRequest;
use Nexus\Mcp\Extension\Tasks\Server\Handler\GetTaskRequestHandler;
use Nexus\Mcp\Extension\Tasks\Server\Store\InMemoryTaskStore;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(GetTaskRequestHandler::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class GetTaskRequestHandlerTest extends AbstractMcpTestCase
{
    public function testAnUnknownTaskIdIsInvalidParams(): void
    {
        $handler = new GetTaskRequestHandler(new InMemoryTaskStore());

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"params.taskId" does not name a known task.');

        $handler->handle(self::buildRequest('missing'), self::buildContext());
    }

    public function testAWorkingTaskProjectsTheBareShape(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('slow_compute', null, 300_000, 1_000)->taskId;
        $handler = new GetTaskRequestHandler($store);

        $result = $handler->handle(self::buildRequest($taskId), self::buildContext());

        self::assertSame(TaskStatus::Working, $result->status);
        self::assertSame($taskId, $result->taskId);
        self::assertSame(300_000, $result->ttlMs);
        self::assertSame(1_000, $result->pollIntervalMs);
        self::assertNull($result->result);
        self::assertNull($result->error);
        self::assertNull($result->inputRequests);
    }

    public function testACompletedTaskCarriesItsResultPayload(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $store->trySetCompleted($taskId, ['resultType' => 'complete', 'content' => []]);
        $handler = new GetTaskRequestHandler($store);

        $result = $handler->handle(self::buildRequest($taskId), self::buildContext());

        self::assertSame(TaskStatus::Completed, $result->status);
        self::assertSame(['resultType' => 'complete', 'content' => []], $result->result);
    }

    public function testAFailedTaskCarriesItsErrorAndStatusMessage(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('failing_job', null, null, 1_000)->taskId;
        $store->trySetFailed($taskId, ['code' => -32_603, 'message' => 'It broke.'], 'Upstream unavailable.');
        $handler = new GetTaskRequestHandler($store);

        $result = $handler->handle(self::buildRequest($taskId), self::buildContext());

        self::assertSame(TaskStatus::Failed, $result->status);
        self::assertSame(['code' => -32_603, 'message' => 'It broke.'], $result->error);
        self::assertSame('Upstream unavailable.', $result->statusMessage);
    }

    public function testAnInputRequiredTaskCarriesItsPendingRequests(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('confirm_delete', null, null, 1_000)->taskId;
        $request = new ElicitRequest(new ElicitRequestFormParams(
            message: 'Confirm?',
            requestedSchema: new ElicitRequestedSchema(properties: ['name' => new StringSchema()]),
        ));
        $store->trySetInputRequired($taskId, ['confirm' => $request], 'state-1');
        $handler = new GetTaskRequestHandler($store);

        $result = $handler->handle(self::buildRequest($taskId), self::buildContext());

        self::assertSame(TaskStatus::InputRequired, $result->status);
        self::assertSame(['confirm' => $request], $result->inputRequests);
        self::assertArrayNotHasKey('requestState', $result->toArray());
    }

    /**
     * @param non-empty-string $taskId
     */
    private static function buildRequest(string $taskId): GetTaskRequest
    {
        return GetTaskRequest::fromArray([
            'id' => 7,
            'params' => ['_meta' => RequestMetaObjectFactory::shape(), 'taskId' => $taskId],
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
}
