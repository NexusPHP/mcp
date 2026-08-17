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
use Nexus\Mcp\Server\RequestHandlerDecoratorInterface;
use Nexus\Mcp\Server\ServerExtensionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The official tasks extension (`io.modelcontextprotocol/tasks`, SEP-2663) for
 * the server. Use one instance per builder, since the runner binds that
 * builder's `tools/call` handler.
 */
final readonly class TasksServerExtension implements RequestHandlerDecoratorInterface, ServerExtensionInterface
{
    private TaskCancellationRegistry $cancellations;
    private ToolTaskRunner $runner;

    /**
     * @param array<non-empty-string, ToolTaskPolicy> $toolPolicies Keyed by tool name, absence meaning always-synchronous
     * @param null|int                                $defaultTtlMs Retention after a terminal transition, `null` for unlimited
     */
    public function __construct(
        private TaskStoreInterface $store = new InMemoryTaskStore(),
        private array $toolPolicies = [],
        private ?int $defaultTtlMs = 300_000,
        private int $defaultPollIntervalMs = 1_000,
        LoggerInterface $logger = new NullLogger(),
    ) {
        $this->cancellations = new TaskCancellationRegistry();
        $this->runner = new ToolTaskRunner($store, $this->cancellations, $logger);
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
            GetTaskRequest::getMethod() => GetTaskRequest::class,
            UpdateTaskRequest::getMethod() => UpdateTaskRequest::class,
            CancelTaskRequest::getMethod() => CancelTaskRequest::class,
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
            GetTaskRequest::getMethod() => new GetTaskRequestHandler($this->store),
            UpdateTaskRequest::getMethod() => new UpdateTaskRequestHandler($this->store, $this->runner),
            CancelTaskRequest::getMethod() => new CancelTaskRequestHandler($this->store, $this->cancellations),
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
