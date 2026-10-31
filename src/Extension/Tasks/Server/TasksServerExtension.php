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

namespace Nexus\Mcp\Extension\Tasks\Server;

use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\CancelTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\GetTaskRequest;
use Nexus\Mcp\Extension\Tasks\Schema\Request\UpdateTaskRequest;
use Nexus\Mcp\Extension\Tasks\Server\Handler\CancelTaskRequestHandler;
use Nexus\Mcp\Extension\Tasks\Server\Handler\GetTaskRequestHandler;
use Nexus\Mcp\Extension\Tasks\Server\Handler\TaskBrokeringCallToolHandler;
use Nexus\Mcp\Extension\Tasks\Server\Handler\UpdateTaskRequestHandler;
use Nexus\Mcp\Extension\Tasks\Server\Store\InMemoryTaskStore;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskStoreInterface;
use Nexus\Mcp\Extension\Tasks\Tasks;
use Nexus\Mcp\Server\Auth\OperationScopes;
use Nexus\Mcp\Server\Extension\RequestHandlerDecoratorInterface;
use Nexus\Mcp\Server\Extension\ServerExtensionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The official tasks extension (`io.modelcontextprotocol/tasks`, SEP-2663) for
 * the server. Use one instance per builder, since the runner binds that
 * builder's `tools/call` handler.
 */
final readonly class TasksServerExtension implements RequestHandlerDecoratorInterface, ServerExtensionInterface
{
    public const int DEFAULT_MAX_RUNNING_TASKS = 1_024;

    private TaskCancellationRegistry $cancellations;
    private ToolTaskRunner $runner;

    /**
     * @param array<non-empty-string, ToolTaskPolicy> $toolPolicies    Keyed by tool name, absence meaning always-synchronous
     * @param null|int                                $defaultTtlMs    Retention after a terminal transition, `null` for unlimited
     * @param int<1, max>                             $maxRunningTasks Tasks running at once before a further one is refused
     * @param null|OperationScopes                    $operationScopes The scopes of each tool, which `tasks/get`, `tasks/update` and `tasks/cancel` require of a task's tool
     */
    public function __construct(
        private TaskStoreInterface $store = new InMemoryTaskStore(),
        private array $toolPolicies = [],
        private ?int $defaultTtlMs = 300_000,
        private int $defaultPollIntervalMs = 1_000,
        LoggerInterface $logger = new NullLogger(),
        int $maxRunningTasks = self::DEFAULT_MAX_RUNNING_TASKS,
        private ?OperationScopes $operationScopes = null,
    ) {
        $this->cancellations = new TaskCancellationRegistry();
        $this->runner = new ToolTaskRunner($store, $this->cancellations, $logger, $maxRunningTasks);
    }

    #[\Override]
    public function getIdentifier(): string
    {
        return Tasks::IDENTIFIER;
    }

    #[\Override]
    public function getSettings(): array
    {
        return [];
    }

    #[\Override]
    public function getRequests(): array
    {
        return [
            GetTaskRequest::class,
            UpdateTaskRequest::class,
            CancelTaskRequest::class,
        ];
    }

    #[\Override]
    public function getNotifications(): array
    {
        return [];
    }

    #[\Override]
    public function getRequestHandlers(): array
    {
        return [
            GetTaskRequest::getMethod() => new GetTaskRequestHandler($this->store, $this->operationScopes),
            UpdateTaskRequest::getMethod() => new UpdateTaskRequestHandler($this->store, $this->runner, $this->operationScopes),
            CancelTaskRequest::getMethod() => new CancelTaskRequestHandler($this->store, $this->cancellations, $this->operationScopes),
        ];
    }

    #[\Override]
    public function getNotificationHandlers(): array
    {
        return [];
    }

    #[\Override]
    public function getRequestHandlerDecorators(): array
    {
        return [
            CallToolRequest::getMethod() => function (RequestHandlerInterface $inner): RequestHandlerInterface {
                $this->runner->bindInnerHandler($inner);

                return new TaskBrokeringCallToolHandler(
                    inner: $inner,
                    identifier: Tasks::IDENTIFIER,
                    store: $this->store,
                    runner: $this->runner,
                    toolPolicies: $this->toolPolicies,
                    defaultTtlMs: $this->defaultTtlMs,
                    defaultPollIntervalMs: $this->defaultPollIntervalMs,
                );
            },
        ];
    }
}
