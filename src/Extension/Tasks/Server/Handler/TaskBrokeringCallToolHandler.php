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

namespace Nexus\Mcp\Extension\Tasks\Server\Handler;

use Nexus\Mcp\Core\Handler\AbstractContext;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcRequest;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Extension\Tasks\Schema\Result\CreateTaskResult;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskStoreInterface;
use Nexus\Mcp\Extension\Tasks\Server\TaskSupport;
use Nexus\Mcp\Extension\Tasks\Server\ToolTaskPolicy;
use Nexus\Mcp\Extension\Tasks\Server\ToolTaskRunner;
use Nexus\Mcp\Server\Handler\Request\ExtensionDeclarationGate;
use Nexus\Mcp\Server\ServerContext;

/**
 * Decorates the `tools/call` handler with the server's per-request task decision.
 *
 * @internal
 *
 * @implements RequestHandlerInterface<'tools/call', Result, ServerContext>
 */
final readonly class TaskBrokeringCallToolHandler implements RequestHandlerInterface
{
    /**
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $inner
     * @param non-empty-string                                                 $identifier
     * @param array<non-empty-string, ToolTaskPolicy>                          $toolPolicies
     */
    public function __construct(
        private RequestHandlerInterface $inner,
        private string $identifier,
        private TaskStoreInterface $store,
        private ToolTaskRunner $runner,
        private array $toolPolicies,
        private ?int $defaultTtlMs,
        private int $defaultPollIntervalMs,
    ) {
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, AbstractContext $context): Result
    {
        \assert($request instanceof CallToolRequest);
        \assert($context instanceof ServerContext);

        $policy = $this->toolPolicies[$request->params->name] ?? null;

        if (null === $policy) {
            return $this->inner->handle($request, $context);
        }

        if (! ExtensionDeclarationGate::declares($context, $this->identifier)) {
            if (TaskSupport::Required === $policy->support) {
                throw ExtensionDeclarationGate::refuse($context, $this->identifier);
            }

            return $this->inner->handle($request, $context);
        }

        if ($policy->resolvesInputFirst && null === $context->requestState) {
            return $this->inner->handle($request, $context);
        }

        $record = $this->store->createTask(
            $request->params->name,
            $request->params->arguments,
            $this->defaultTtlMs,
            $this->defaultPollIntervalMs,
        );

        $this->runner->startTask($record->taskId, $request, $context, $context->inputResponses, $context->requestState);

        return new CreateTaskResult(
            taskId: $record->taskId,
            status: $record->status,
            createdAt: $record->createdAt,
            lastUpdatedAt: $record->lastUpdatedAt,
            ttlMs: $record->ttlMs,
            pollIntervalMs: $record->pollIntervalMs,
        );
    }
}
