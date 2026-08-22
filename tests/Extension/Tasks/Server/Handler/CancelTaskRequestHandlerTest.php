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
use Nexus\Mcp\Core\Schema\RequestId;
use Nexus\Mcp\Extension\Tasks\Schema\Enum\TaskStatus;
use Nexus\Mcp\Extension\Tasks\Schema\Request\CancelTaskRequest;
use Nexus\Mcp\Extension\Tasks\Server\Handler\CancelTaskRequestHandler;
use Nexus\Mcp\Extension\Tasks\Server\Store\InMemoryTaskStore;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskRecord;
use Nexus\Mcp\Extension\Tasks\Server\TaskCancellationRegistry;
use Nexus\Mcp\Server\ServerContext;
use Nexus\Mcp\Tests\AbstractMcpTestCase;
use Nexus\Mcp\Tests\Fixtures\Core\Handler\RecordingSender;
use Nexus\Mcp\Tests\Fixtures\Core\Schema\RequestMetaObjectFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[CoversClass(CancelTaskRequestHandler::class)]
#[Group('unit-tests')]
#[Group('extension-tests')]
final class CancelTaskRequestHandlerTest extends AbstractMcpTestCase
{
    public function testAnUnknownTaskIdIsInvalidParams(): void
    {
        $handler = new CancelTaskRequestHandler(new InMemoryTaskStore(), new TaskCancellationRegistry());

        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessageIs('"params.taskId" does not name a known task.');

        $handler->handle($this->buildRequest('missing'), $this->buildContext());
    }

    public function testCancelMarksTheRecordAndCancelsTheInFlightFiber(): void
    {
        $store = new InMemoryTaskStore();
        $registry = new TaskCancellationRegistry();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $token = $registry->register($taskId);

        $handler = new CancelTaskRequestHandler($store, $registry);
        $handler->handle($this->buildRequest($taskId), $this->buildContext());

        self::assertTrue($token->isRequested());

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Cancelled, $record->status);
    }

    public function testCancelOfATerminalTaskIsIdempotent(): void
    {
        $store = new InMemoryTaskStore();
        $taskId = $store->createTask('slow_compute', null, null, 1_000)->taskId;
        $store->trySetCompleted($taskId, ['resultType' => 'complete']);

        $handler = new CancelTaskRequestHandler($store, new TaskCancellationRegistry());
        $handler->handle($this->buildRequest($taskId), $this->buildContext());

        $record = $store->findTask($taskId);
        self::assertInstanceOf(TaskRecord::class, $record);
        self::assertSame(TaskStatus::Completed, $record->status);
    }

    /**
     * @param non-empty-string $taskId
     */
    private function buildRequest(string $taskId): CancelTaskRequest
    {
        return CancelTaskRequest::fromArray([
            'id' => 7,
            'params' => ['_meta' => RequestMetaObjectFactory::shape(), 'taskId' => $taskId],
        ]);
    }

    private function buildContext(): ServerContext
    {
        return new ServerContext(
            new RequestId(id: 7),
            new NullCancellation(),
            RequestMetaObjectFactory::create(),
            new RecordingSender(),
        );
    }
}
