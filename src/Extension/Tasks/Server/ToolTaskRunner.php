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

use Amp\CancelledException;
use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Handler\RequestHandlerInterface;
use Nexus\Mcp\Core\JsonRpc\ErrorFactory;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\Request\CallToolRequest;
use Nexus\Mcp\Core\Schema\Result;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\InputResponse;
use Nexus\Mcp\Extension\Tasks\Server\Exception\InputRequestKeyReusedException;
use Nexus\Mcp\Extension\Tasks\Server\Store\TaskStoreInterface;
use Nexus\Mcp\Server\ServerContext;
use Psr\Log\LoggerInterface;

use function Amp\async;

/**
 * Runs a task's tool call in a background fiber and maps its outcome onto the
 * store: any complete result (an `isError` tool failure included) completes
 * the task, an `InputRequiredResult` parks it, a protocol exception fails it,
 * and cancellation settles it as cancelled.
 *
 * @internal
 */
final class ToolTaskRunner
{
    /**
     * The effective `tools/call` handler the broker decorates, bound when the
     * builder applies the decorator and therefore before any task can start.
     *
     * @var null|RequestHandlerInterface<non-empty-string, Result, ServerContext>
     */
    private ?RequestHandlerInterface $inner = null;

    public function __construct(
        private readonly TaskStoreInterface $store,
        private readonly TaskCancellationRegistry $cancellations,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $inner
     */
    public function bindInnerHandler(RequestHandlerInterface $inner): void
    {
        $this->inner = $inner;
    }

    /**
     * Starts the task's tool call in a background fiber bound to its own
     * cancellation source, detached from the creating request's lifecycle.
     * The caller supplies the continuation state the background context runs
     * with, whether carried over from the origin request or accumulated by
     * the store.
     *
     * @param non-empty-string                  $taskId
     * @param ServerContext                     $origin         The context of the request that triggered the run
     * @param null|array<string, InputResponse> $inputResponses Continuation answers for the background context
     * @param null|string                       $requestState   Continuation token for the background context
     */
    public function startTask(string $taskId, CallToolRequest $request, ServerContext $origin, ?array $inputResponses, ?string $requestState): void
    {
        $inner = $this->inner;

        if (null === $inner) {
            throw new \LogicException('The tasks broker has not been applied, so no tool handler can serve the task.');
        }

        $background = new ServerContext(
            requestId: $origin->requestId,
            cancellation: $this->cancellations->register($taskId),
            meta: $origin->meta,
            sender: new DetachedTaskSender($this->logger, $origin->requestId),
            receiveContext: $origin->receiveContext,
            inputResponses: $inputResponses,
            requestState: $requestState,
        );

        async(function () use ($taskId, $request, $background, $inner): void {
            // `settleOutcome()` catches every throwable, so the release always runs.
            $this->settleOutcome($taskId, $inner, $request, $background);
            $this->cancellations->release($taskId);
        })->ignore();
    }

    /**
     * @param non-empty-string                                                 $taskId
     * @param RequestHandlerInterface<non-empty-string, Result, ServerContext> $inner
     */
    private function settleOutcome(string $taskId, RequestHandlerInterface $inner, CallToolRequest $request, ServerContext $context): void
    {
        try {
            $result = $inner->handle($request, $context);

            if ($result instanceof InputRequiredResult) {
                if (null === $result->inputRequests || [] === $result->inputRequests) {
                    // An input_required task must surface requests a client can
                    // answer, so a requestState-only park is unresumable.
                    $this->store->trySetFailed($taskId, ErrorFactory::create(
                        ProtocolErrorCode::InternalError,
                        'The tool parked the task without any input requests.',
                    )->toArray());

                    return;
                }

                $this->store->trySetInputRequired($taskId, $result->inputRequests, $result->requestState);

                return;
            }

            $this->store->trySetCompleted($taskId, $result->toArray());
        } catch (CancelledException) {
            // `tasks/cancel` already marked the record, so this is a no-op there.
            $this->store->trySetCancelled($taskId);
        } catch (InputRequestKeyReusedException $e) {
            $this->store->trySetFailed($taskId, ErrorFactory::create(
                ProtocolErrorCode::InternalError,
                $e->getMessage(),
            )->toArray());
        } catch (AbstractJsonRpcProtocolException $e) {
            $this->store->trySetFailed($taskId, ErrorFactory::create(
                $e::getErrorCode(),
                $e->getMessage(),
                $e->errorData,
            )->toArray());
        } catch (\Throwable $e) {
            // Generic peer-facing payload. Raw $e->getMessage() can carry paths or secrets.
            $this->logger->error(
                'Uncaught task executor exception. Failing task {taskId} with a generic error.',
                ['taskId' => $taskId, 'exception' => $e],
            );

            $this->store->trySetFailed($taskId, ErrorFactory::create(
                ProtocolErrorCode::InternalError,
                'Task execution failed.',
            )->toArray());
        }
    }
}
